-- Phase 5B deterministic School Sales item-type lookup rows.
-- Run on hf_db_bfim0j6t after the Phase 5B foundation migration.
-- Creates lookup rows only; no catalog business data is seeded.

DROP PROCEDURE IF EXISTS phase5b_seed_controlled_item_types;
DELIMITER //
CREATE PROCEDURE phase5b_seed_controlled_item_types()
BEGIN
    DECLARE total_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item-type seed blocked: wrong Payment database.';
    END IF;

    SELECT COUNT(*) INTO total_rows FROM school_sale_item_types;
    SELECT COUNT(*) INTO exact_rows
    FROM school_sale_item_types
    WHERE (type_code = 'UNIFORM' AND type_name = 'Uniform' AND metadata_profile = 'UNIFORM_VARIANT' AND status = 'Active' AND sort_order = 10)
       OR (type_code = 'BOOK' AND type_name = 'Book' AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active' AND sort_order = 20)
       OR (type_code = 'LEARNING_MATERIAL' AND type_name = 'Learning Material' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 30)
       OR (type_code = 'ACCESSORY' AND type_name = 'Accessory' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 40)
       OR (type_code = 'GRADUATION_ITEM' AND type_name = 'Graduation Item' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 50)
       OR (type_code = 'OTHER_MERCHANDISE' AND type_name = 'Other Merchandise' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 60);

    IF total_rows = 0 THEN
        INSERT INTO school_sale_item_types
            (type_code, type_name, metadata_profile, status, sort_order, created_by, updated_by)
        VALUES
            ('UNIFORM', 'Uniform', 'UNIFORM_VARIANT', 'Active', 10, NULL, NULL),
            ('BOOK', 'Book', 'BOOK_REQUIRED', 'Active', 20, NULL, NULL),
            ('LEARNING_MATERIAL', 'Learning Material', 'STANDARD_PRODUCT', 'Active', 30, NULL, NULL),
            ('ACCESSORY', 'Accessory', 'STANDARD_PRODUCT', 'Active', 40, NULL, NULL),
            ('GRADUATION_ITEM', 'Graduation Item', 'STANDARD_PRODUCT', 'Active', 50, NULL, NULL),
            ('OTHER_MERCHANDISE', 'Other Merchandise', 'STANDARD_PRODUCT', 'Active', 60, NULL, NULL);
    ELSEIF total_rows = 6 AND exact_rows = 6 THEN
        SET exact_rows = exact_rows;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item-type seed blocked: CONFLICTING lookup state.';
    END IF;
END//
DELIMITER ;

CALL phase5b_seed_controlled_item_types();
DROP PROCEDURE phase5b_seed_controlled_item_types;

