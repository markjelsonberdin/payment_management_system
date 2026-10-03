-- Phase 4B production catalog reconciliation rollback.
-- Use only after a failed deployment and only before any new catalog data is added.
-- Take a backup first. The guard refuses rollback once item/type data exists or
-- the four preserved categories no longer match their reconciled state.
-- This rollback does not remove or alter the separate core audit extension.

-- Target: Hostforge production Payment DB hf_db_bfim0j6t.

DROP PROCEDURE IF EXISTS phase4b_assert_catalog_reconciliation_rollback_safe;
DELIMITER //
CREATE PROCEDURE phase4b_assert_catalog_reconciliation_rollback_safe()
BEGIN
    DECLARE category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE expected_category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_type_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Wrong database for Phase 4B production reconciliation rollback.';
    END IF;

    SELECT COUNT(*) INTO category_rows FROM school_sale_categories;
    SELECT COUNT(*) INTO expected_category_rows
    FROM school_sale_categories
    WHERE (sale_category_id = 1 AND category_code = 'UNIFORM' AND category_name = 'Uniform')
       OR (sale_category_id = 2 AND category_code = 'BOOKS' AND category_name = 'Books')
       OR (sale_category_id = 3 AND category_code = 'GRADUATION' AND category_name = 'Graduation')
       OR (sale_category_id = 4 AND category_code = 'MISCELLANEOUS' AND category_name = 'Miscellaneous');
    SELECT COUNT(*) INTO item_type_rows FROM school_sale_item_types;
    SELECT COUNT(*) INTO item_rows FROM school_sale_items;

    IF category_rows <> 4 OR expected_category_rows <> 4
       OR item_type_rows <> 0 OR item_rows <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Production reconciliation rollback blocked: catalog data changed.';
    END IF;
END//
DELIMITER ;

CALL phase4b_assert_catalog_reconciliation_rollback_safe();
DROP PROCEDURE phase4b_assert_catalog_reconciliation_rollback_safe;

ALTER TABLE school_sale_items
    DROP FOREIGN KEY fk_school_sale_item_type,
    DROP FOREIGN KEY fk_school_sale_item_category,
    DROP INDEX uq_school_sale_item_code,
    DROP INDEX idx_school_sale_item_category_status,
    DROP INDEX idx_school_sale_item_type_status,
    DROP INDEX idx_school_sale_item_name;

ALTER TABLE school_sale_items
    DROP COLUMN item_code,
    DROP COLUMN sale_item_type_id,
    DROP COLUMN description,
    DROP COLUMN applicability_mode,
    DROP COLUMN created_by,
    DROP COLUMN updated_by,
    ADD COLUMN unit_price DECIMAL(10,2) NOT NULL AFTER item_name,
    MODIFY COLUMN status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER unit_price,
    MODIFY COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
    MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY idx_sale_item_category_status (sale_category_id, status),
    ADD CONSTRAINT fk_sale_item_category
        FOREIGN KEY (sale_category_id) REFERENCES school_sale_categories (sale_category_id);

DROP TABLE school_sale_item_types;

ALTER TABLE school_sale_categories
    DROP INDEX uq_school_sale_category_code,
    DROP INDEX idx_school_sale_category_status_sort,
    DROP COLUMN category_code,
    DROP COLUMN created_by,
    DROP COLUMN updated_by,
    DROP COLUMN updated_at,
    MODIFY COLUMN category_name VARCHAR(100) NOT NULL AFTER sale_category_id,
    MODIFY COLUMN status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER category_name,
    MODIFY COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status,
    MODIFY COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sort_order,
    ADD UNIQUE KEY uq_school_sale_category_name (category_name);

-- Existing category rows, cash/receipt tables, receipt sequence, fees, billing,
-- payments, and all other production data remain in place.

