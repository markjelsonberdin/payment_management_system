-- Phase 5B guarded rollback for Payment DB hf_db_bfim0j6t.
-- This rollback refuses to discard any catalog or outbox history.
-- Export/retain the database first. There is no automatic outbox purge.

DROP PROCEDURE IF EXISTS phase5b_assert_rollback_safe;
DELIMITER //
CREATE PROCEDURE phase5b_assert_rollback_safe()
BEGIN
    DECLARE required_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE business_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE type_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_type_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B rollback blocked: wrong Payment database.';
    END IF;

    SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                         'school_sale_item_applicability', 'payment_audit_outbox');

    IF required_tables <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B rollback blocked: incomplete or unexpected Phase 5B schema.';
    END IF;

    SELECT (SELECT COUNT(*) FROM school_sale_items)
         + (SELECT COUNT(*) FROM school_sale_item_variants)
         + (SELECT COUNT(*) FROM school_sale_variant_prices)
         + (SELECT COUNT(*) FROM school_sale_item_applicability)
         + (SELECT COUNT(*) FROM payment_audit_outbox)
      INTO business_rows;

    IF business_rows <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B rollback blocked: catalog or outbox rows exist, including possibly Delivered audit records.';
    END IF;

    SELECT COUNT(*) INTO type_rows FROM school_sale_item_types;
    SELECT COUNT(*) INTO exact_type_rows
    FROM school_sale_item_types
    WHERE (type_code = 'UNIFORM' AND type_name = 'Uniform' AND metadata_profile = 'UNIFORM_VARIANT' AND status = 'Active' AND sort_order = 10)
       OR (type_code = 'BOOK' AND type_name = 'Book' AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active' AND sort_order = 20)
       OR (type_code = 'LEARNING_MATERIAL' AND type_name = 'Learning Material' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 30)
       OR (type_code = 'ACCESSORY' AND type_name = 'Accessory' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 40)
       OR (type_code = 'GRADUATION_ITEM' AND type_name = 'Graduation Item' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 50)
       OR (type_code = 'OTHER_MERCHANDISE' AND type_name = 'Other Merchandise' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 60);

    IF type_rows <> 6 OR exact_type_rows <> 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B rollback blocked: item-type lookup is not the exact controlled seed set.';
    END IF;
END//
DELIMITER ;

CALL phase5b_assert_rollback_safe();
DROP PROCEDURE phase5b_assert_rollback_safe;

DROP TABLE school_sale_item_applicability;
DROP TABLE school_sale_variant_prices;
DROP TABLE school_sale_item_variants;
DROP TABLE payment_audit_outbox;

DELETE FROM school_sale_item_types
WHERE type_code IN ('UNIFORM', 'BOOK', 'LEARNING_MATERIAL', 'ACCESSORY',
                    'GRADUATION_ITEM', 'OTHER_MERCHANDISE');

-- Core DB rollback is intentionally separate because Core is a different host.
-- Only after confirming that no committed catalog mutation depends on the unique
-- correlation key, connect directly to hf_db_w3krndst and run manually:
-- ALTER TABLE activity_logs
--   DROP INDEX uq_logs_correlation,
--   ADD KEY idx_logs_correlation (correlation_id);
