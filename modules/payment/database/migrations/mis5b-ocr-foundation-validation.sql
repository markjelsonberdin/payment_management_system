-- MIS-5B post-migration structural validation. Read-only.
DELIMITER $$
DROP PROCEDURE IF EXISTS mis5b_ocr_validate$$
CREATE PROCEDURE mis5b_ocr_validate() SQL SECURITY INVOKER
BEGIN
 DECLARE n INT DEFAULT 0;
 SELECT COUNT(*) INTO n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN ('ocr_usage_months','ocr_usage_ledger','ocr_scan_attempts','ocr_image_cache');
 IF n<>4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: expected four InnoDB tables'; END IF;
 SELECT COUNT(*) INTO n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='PRIMARY KEY' AND TABLE_NAME IN ('ocr_usage_months','ocr_usage_ledger','ocr_scan_attempts','ocr_image_cache');
 IF n<>4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: primary key mismatch'; END IF;
 SELECT COUNT(*) INTO n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 AND INDEX_NAME IN ('uq_ocr_usage_request','uq_ocr_usage_idempotency','uq_ocr_attempt_request','uq_ocr_attempt_number');
 IF n<>5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: unique index mismatch'; END IF;
 SELECT COUNT(*) INTO n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('fk_ocr_usage_month','fk_ocr_usage_concern','fk_ocr_attempt_usage','fk_ocr_attempt_concern','fk_ocr_cache_attempt');
 IF n<>5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: foreign key mismatch'; END IF;
 SELECT COUNT(*) INTO n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ocr_image_cache' AND INDEX_NAME='PRIMARY' AND COLUMN_NAME IN ('image_sha256','provider','feature','feature_version');
 IF n<>4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: cache key is not version-aware'; END IF;
 SELECT COUNT(*) INTO n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ocr_scan_attempts' AND COLUMN_NAME IN ('normalized_text','provider','feature','feature_version','parser_version','image_sha256','extracted_amount','reference_number','transaction_date','transaction_time','confidence_score','quality_json','failure_category','cache_hit','cache_source_attempt_id');
 IF n<>15 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIS5B_VALIDATE: evidence columns missing'; END IF;
 SELECT 'PASS' validation_status,DATABASE() target_database,'Runtime regression must prove atomic reservation and 899/900 concurrency.' remaining_validation;
END$$
CALL mis5b_ocr_validate()$$
DROP PROCEDURE mis5b_ocr_validate$$
DELIMITER ;
