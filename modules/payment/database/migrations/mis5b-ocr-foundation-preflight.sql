-- MIS-5B OCR foundation preflight. Run against payment_db before migration.
DELIMITER $$
DROP PROCEDURE IF EXISTS mis5b_ocr_preflight$$
CREATE PROCEDURE mis5b_ocr_preflight() SQL SECURITY INVOKER
BEGIN
  DECLARE n INT DEFAULT 0; DECLARE eng VARCHAR(64); DECLARE col VARCHAR(255); DECLARE db_charset VARCHAR(64);
  IF VERSION() NOT LIKE '%MariaDB%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: MariaDB required'; END IF;
  IF CAST(SUBSTRING_INDEX(VERSION(),'.',1) AS UNSIGNED)<10 OR (CAST(SUBSTRING_INDEX(VERSION(),'.',1) AS UNSIGNED)=10 AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(VERSION(),'.',2),'.',-1) AS UNSIGNED)<2) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: MariaDB 10.2+ required for CHECK and JSON_VALID'; END IF;
  SELECT DEFAULT_CHARACTER_SET_NAME INTO db_charset FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE();
  IF db_charset<>'utf8mb4' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: payment_db must use utf8mb4'; END IF;
  SELECT COUNT(*),MAX(ENGINE) INTO n,eng FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_concerns';
  IF n<>1 OR UPPER(COALESCE(eng,''))<>'INNODB' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: payment_concerns must be InnoDB'; END IF;
  SELECT COUNT(*),MAX(COLUMN_TYPE) INTO n,col FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_concerns' AND COLUMN_NAME='concern_id' AND IS_NULLABLE='NO' AND COLUMN_KEY='PRI';
  IF n<>1 OR LOWER(COALESCE(col,''))<>'int(10) unsigned' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: concern_id must be INT(10) UNSIGNED PK'; END IF;
  SELECT COUNT(*) INTO n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ocr_results' AND ENGINE='InnoDB';
  IF n<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: existing ocr_results required'; END IF;
  SELECT COUNT(*) INTO n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ocr_results' AND ((COLUMN_NAME='ocr_result_id' AND LOWER(COLUMN_TYPE)='int(10) unsigned' AND COLUMN_KEY='PRI') OR (COLUMN_NAME='concern_id' AND LOWER(COLUMN_TYPE)='int(10) unsigned'));
  IF n<>2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: unexpected ocr_results identity'; END IF;
  SELECT COUNT(*) INTO n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ocr_usage_months','ocr_usage_ledger','ocr_scan_attempts','ocr_image_cache');
  IF n<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: partial/existing MIS-5B schema'; END IF;
  SELECT COUNT(*) INTO n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('uq_ocr_usage_idempotency','uq_ocr_usage_request','uq_ocr_attempt_request','uq_ocr_attempt_number');
  IF n<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: conflicting indexes'; END IF;
  SELECT COUNT(*) INTO n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('fk_ocr_usage_month','fk_ocr_usage_concern','fk_ocr_attempt_usage','fk_ocr_attempt_concern','fk_ocr_attempt_cache_source','fk_ocr_cache_attempt');
  IF n<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_PREFLIGHT: conflicting foreign keys'; END IF;
  SELECT 'PASS' preflight_status,DATABASE() target_database,VERSION() mariadb_version,db_charset database_charset,eng parent_engine,col concern_id_definition,'Verify SHOW GRANTS includes CREATE, ALTER, INDEX, REFERENCES, SELECT, INSERT, UPDATE.' privilege_check;
END$$
CALL mis5b_ocr_preflight()$$
DROP PROCEDURE mis5b_ocr_preflight$$
DELIMITER ;
