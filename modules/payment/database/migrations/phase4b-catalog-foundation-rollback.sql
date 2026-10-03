-- Phase 4B rollback.
-- Execute only when all checks below return zero. This rollback is intentionally
-- unavailable after catalog data or structured audit data has been recorded.

-- A prior interrupted rollback may leave this temporary guard procedure behind.
DROP PROCEDURE IF EXISTS sms2_phase4b_assert_rollback_safe;

DELIMITER //
CREATE PROCEDURE sms2_phase4b_assert_rollback_safe()
BEGIN
    DECLARE category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_type_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE structured_audit_rows BIGINT UNSIGNED DEFAULT 0;

    SELECT COUNT(*) INTO category_rows FROM payment_db_test.school_sale_categories;
    SELECT COUNT(*) INTO item_type_rows FROM payment_db_test.school_sale_item_types;
    SELECT COUNT(*) INTO item_rows FROM payment_db_test.school_sale_items;
    SELECT COUNT(*) INTO structured_audit_rows
    FROM sms2_db.activity_logs
    WHERE entity_type IS NOT NULL OR entity_id IS NOT NULL OR before_state IS NOT NULL
       OR after_state IS NOT NULL OR correlation_id IS NOT NULL;

    IF category_rows <> 0 OR item_type_rows <> 0 OR item_rows <> 0
       OR structured_audit_rows <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Phase 4B rollback blocked: catalog or structured audit data exists.';
    END IF;
END//
DELIMITER ;

CALL sms2_phase4b_assert_rollback_safe();
DROP PROCEDURE sms2_phase4b_assert_rollback_safe;

-- The guard above must complete before any irreversible DDL is reached.

USE payment_db_test;
DROP TABLE school_sale_items;
DROP TABLE school_sale_item_types;
DROP TABLE school_sale_categories;

USE sms2_db;
ALTER TABLE activity_logs
    DROP INDEX idx_logs_entity,
    DROP INDEX idx_logs_module_action_created,
    DROP INDEX idx_logs_correlation,
    DROP COLUMN correlation_id,
    DROP COLUMN after_state,
    DROP COLUMN before_state,
    DROP COLUMN entity_id,
    DROP COLUMN entity_type;

-- This rollback never touches fees, fee_categories, billing, payments, receipts,
-- variants, prices, applicability records, cash sales, or existing audit fields.
