-- Phase 4B production catalog reconciliation migration.
-- Target: Hostforge production Payment DB hf_db_bfim0j6t.
-- Prerequisite: every query in phase4b-production-catalog-reconciliation-preflight.sql returns PASS.
-- DDL causes implicit commits in MariaDB. Take a verified backup before execution.
-- This migration does not alter fees, billing, cash sales, receipts, variants,
-- prices, applicability records, or the official receipt sequence.

-- Refuse to run unless production still matches the audited legacy state.
DROP PROCEDURE IF EXISTS phase4b_assert_catalog_reconciliation_safe;
DELIMITER //
CREATE PROCEDURE phase4b_assert_catalog_reconciliation_safe()
BEGIN
    DECLARE category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE expected_category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE sale_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE sale_line_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE print_event_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_type_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE legacy_price_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE target_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE legacy_constraints BIGINT UNSIGNED DEFAULT 0;
    DECLARE legacy_item_indexes BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Wrong database for Phase 4B production reconciliation.';
    END IF;

    SELECT COUNT(*) INTO category_rows FROM school_sale_categories;
    SELECT COUNT(*) INTO expected_category_rows
    FROM school_sale_categories
    WHERE (sale_category_id = 1 AND category_name = 'Uniform' AND status = 'Active' AND sort_order = 10)
       OR (sale_category_id = 2 AND category_name = 'Books' AND status = 'Active' AND sort_order = 20)
       OR (sale_category_id = 3 AND category_name = 'Graduation' AND status = 'Active' AND sort_order = 30)
       OR (sale_category_id = 4 AND category_name = 'Miscellaneous' AND status = 'Active' AND sort_order = 40);
    SELECT COUNT(*) INTO item_rows FROM school_sale_items;
    SELECT COUNT(*) INTO sale_rows FROM cash_sales;
    SELECT COUNT(*) INTO sale_line_rows FROM cash_sale_items;
    SELECT COUNT(*) INTO print_event_rows FROM cashier_receipt_print_events;

    SELECT COUNT(*) INTO item_type_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_item_types';

    SELECT COUNT(*) INTO legacy_price_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'school_sale_items'
      AND column_name = 'unit_price'
      AND column_type = 'decimal(10,2)';

    SELECT COUNT(*) INTO target_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND (
          (table_name = 'school_sale_categories'
           AND column_name IN ('category_code', 'created_by', 'updated_by', 'updated_at'))
          OR
          (table_name = 'school_sale_items'
           AND column_name IN ('item_code', 'sale_item_type_id', 'description', 'applicability_mode', 'created_by', 'updated_by'))
      );

    SELECT COUNT(*) INTO legacy_constraints
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND (
          (table_name = 'school_sale_categories'
           AND constraint_name = 'uq_school_sale_category_name'
           AND constraint_type = 'UNIQUE')
          OR
          (table_name = 'school_sale_items'
           AND constraint_name = 'fk_sale_item_category'
           AND constraint_type = 'FOREIGN KEY')
      );

    SELECT COUNT(DISTINCT index_name) INTO legacy_item_indexes
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'school_sale_items'
      AND index_name = 'idx_sale_item_category_status';

    IF category_rows <> 4 OR expected_category_rows <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Catalog reconciliation blocked: legacy categories changed.';
    END IF;

    IF item_rows <> 0 OR sale_rows <> 0 OR sale_line_rows <> 0 OR print_event_rows <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Catalog reconciliation blocked: item or sale data exists.';
    END IF;

    IF item_type_tables <> 0 OR legacy_price_columns <> 1 OR target_columns <> 0
       OR legacy_constraints <> 2 OR legacy_item_indexes <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Catalog reconciliation blocked: unexpected schema state.';
    END IF;
END//
DELIMITER ;

CALL phase4b_assert_catalog_reconciliation_safe();
DROP PROCEDURE phase4b_assert_catalog_reconciliation_safe;

-- Preserve the four audited category identities and names while adding the
-- approved code, lifecycle, attribution, and update timestamp fields.
ALTER TABLE school_sale_categories
    ADD COLUMN category_code VARCHAR(60) NULL AFTER sale_category_id,
    MODIFY COLUMN category_name VARCHAR(100) NOT NULL AFTER category_code,
    MODIFY COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER category_name,
    MODIFY COLUMN status ENUM('Draft', 'Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Draft' AFTER sort_order,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER status,
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
    MODIFY COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE school_sale_categories
SET category_code = CASE sale_category_id
    WHEN 1 THEN 'UNIFORM'
    WHEN 2 THEN 'BOOKS'
    WHEN 3 THEN 'GRADUATION'
    WHEN 4 THEN 'MISCELLANEOUS'
END
WHERE sale_category_id IN (1, 2, 3, 4);

ALTER TABLE school_sale_categories
    MODIFY COLUMN category_code VARCHAR(60) NOT NULL,
    DROP INDEX uq_school_sale_category_name,
    ADD UNIQUE KEY uq_school_sale_category_code (category_code),
    ADD KEY idx_school_sale_category_status_sort (status, sort_order);

CREATE TABLE school_sale_item_types (
    sale_item_type_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_code VARCHAR(60) NOT NULL,
    type_name VARCHAR(120) NOT NULL,
    metadata_profile VARCHAR(60) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_item_type_id),
    UNIQUE KEY uq_school_sale_item_type_code (type_code),
    UNIQUE KEY uq_school_sale_item_type_name (type_name),
    KEY idx_school_sale_item_type_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- The legacy item table is empty. Reconcile it in place so its table identity
-- remains stable while removing mutable unit_price and adding the target fields.
ALTER TABLE school_sale_items
    DROP FOREIGN KEY fk_sale_item_category,
    DROP INDEX idx_sale_item_category_status;

ALTER TABLE school_sale_items
    ADD COLUMN item_code VARCHAR(60) NOT NULL AFTER sale_item_id,
    MODIFY COLUMN sale_category_id INT UNSIGNED NOT NULL AFTER item_code,
    ADD COLUMN sale_item_type_id INT UNSIGNED NOT NULL AFTER sale_category_id,
    MODIFY COLUMN item_name VARCHAR(150) NOT NULL AFTER sale_item_type_id,
    ADD COLUMN description TEXT NULL AFTER item_name,
    DROP COLUMN unit_price,
    ADD COLUMN applicability_mode ENUM('ALL', 'RESTRICTED') NOT NULL DEFAULT 'ALL' AFTER description,
    MODIFY COLUMN status ENUM('Draft', 'Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Draft' AFTER applicability_mode,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER status,
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
    MODIFY COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by,
    MODIFY COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_school_sale_item_code (item_code),
    ADD KEY idx_school_sale_item_category_status (sale_category_id, status),
    ADD KEY idx_school_sale_item_type_status (sale_item_type_id, status),
    ADD KEY idx_school_sale_item_name (item_name),
    ADD CONSTRAINT fk_school_sale_item_category
        FOREIGN KEY (sale_category_id) REFERENCES school_sale_categories (sale_category_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_school_sale_item_type
        FOREIGN KEY (sale_item_type_id) REFERENCES school_sale_item_types (sale_item_type_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

-- No item types or items are seeded. Items cannot become Active/sellable until
-- Phase 5 supplies an Active variant and a currently effective official price.

