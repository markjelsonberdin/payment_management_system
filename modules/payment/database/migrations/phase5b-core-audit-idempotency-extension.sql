-- Phase 5B core audit correlation-id idempotency extension.
-- Run separately on the Hostforge core DB connection hf_db_w3krndst.
-- No USE statement: connect directly to the core database.

DROP PROCEDURE IF EXISTS phase5b_apply_core_audit_idempotency;
DELIMITER //
CREATE PROCEDURE phase5b_apply_core_audit_idempotency()
BEGIN
    DECLARE duplicate_correlations BIGINT UNSIGNED DEFAULT 0;
    DECLARE structured_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE old_index_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE unique_index_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_w3krndst' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Core audit extension blocked: wrong database.';
    END IF;
    IF VERSION() NOT LIKE '11.8.8-MariaDB%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Core audit extension blocked: unverified MariaDB version.';
    END IF;

    SELECT COUNT(*) INTO structured_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'activity_logs'
      AND column_name IN ('entity_type', 'entity_id', 'before_state', 'after_state', 'correlation_id');

    SELECT COUNT(*) INTO duplicate_correlations
    FROM (
        SELECT correlation_id
        FROM activity_logs
        WHERE correlation_id IS NOT NULL
        GROUP BY correlation_id
        HAVING COUNT(*) > 1
    ) AS duplicates_found;

    SELECT COUNT(*) INTO old_index_rows
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'activity_logs'
      AND index_name = 'idx_logs_correlation';

    SELECT COUNT(*) INTO unique_index_rows
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'activity_logs'
      AND index_name = 'uq_logs_correlation' AND non_unique = 0;

    IF structured_columns <> 5 OR duplicate_correlations <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Core audit extension blocked: schema or correlation conflict.';
    END IF;

    IF unique_index_rows = 0 THEN
        IF old_index_rows > 0 THEN
            ALTER TABLE activity_logs DROP INDEX idx_logs_correlation;
        END IF;
        ALTER TABLE activity_logs ADD UNIQUE KEY uq_logs_correlation (correlation_id);
    END IF;
END//
DELIMITER ;

CALL phase5b_apply_core_audit_idempotency();
DROP PROCEDURE phase5b_apply_core_audit_idempotency;

SELECT 'core correlation uniqueness' AS check_name,
       CASE WHEN COUNT(*) = 1 AND MAX(non_unique) = 0 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'activity_logs'
  AND index_name = 'uq_logs_correlation';

