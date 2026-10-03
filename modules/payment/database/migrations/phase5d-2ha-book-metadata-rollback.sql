-- Phase 5D-2H-A guarded rollback for school_sale_book_details.
-- Emergency use only. Export/retain the Payment DB before execution.
-- Refuses to remove the table when any Book metadata row exists.

DROP PROCEDURE IF EXISTS phase5d_2ha_assert_book_rollback_safe;
DELIMITER //
CREATE PROCEDURE phase5d_2ha_assert_book_rollback_safe()
BEGIN
    DECLARE target_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE target_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_storage BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_indexes BIGINT UNSIGNED DEFAULT 0;
    DECLARE total_indexes BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_fk BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_checks BIGINT UNSIGNED DEFAULT 0;
    DECLARE total_checks BIGINT UNSIGNED DEFAULT 0;
    DECLARE metadata_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A rollback blocked: wrong Payment database.';
    END IF;
    IF VERSION() NOT LIKE '11.8.8-MariaDB%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A rollback blocked: unverified MariaDB version.';
    END IF;

    SELECT COUNT(*) INTO target_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';
    SELECT COUNT(*) INTO target_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';
    SELECT COUNT(*) INTO exact_storage
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
      AND engine = 'InnoDB' AND table_collation = 'utf8mb4_uca1400_ai_ci';
    SELECT COUNT(*) INTO exact_indexes
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
      AND ((index_name = 'PRIMARY' AND column_name = 'sale_book_detail_id' AND non_unique = 0)
        OR (index_name = 'uq_school_sale_book_item' AND column_name = 'sale_item_id' AND non_unique = 0)
        OR (index_name = 'idx_school_sale_book_isbn' AND column_name = 'isbn' AND non_unique = 1));
    SELECT COUNT(DISTINCT index_name) INTO total_indexes
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';
    SELECT COUNT(*) INTO exact_fk
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
      AND constraint_name = 'fk_school_sale_book_item'
      AND referenced_table_name = 'school_sale_items'
      AND delete_rule = 'RESTRICT' AND update_rule = 'RESTRICT';
    SELECT COUNT(*) INTO exact_checks
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
      AND constraint_type = 'CHECK'
      AND constraint_name IN ('chk_school_sale_book_title_nonblank',
          'chk_school_sale_book_author_nonblank', 'chk_school_sale_book_publisher_nonblank',
          'chk_school_sale_book_edition_nonblank', 'chk_school_sale_book_isbn_format',
          'chk_school_sale_book_notes_nonblank');
    SELECT COUNT(*) INTO total_checks
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
      AND constraint_type = 'CHECK';

    IF target_tables <> 1 OR target_columns <> 12 OR exact_storage <> 1
       OR exact_indexes <> 3 OR total_indexes <> 3 OR exact_fk <> 1
       OR exact_checks <> 6 OR total_checks <> 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A rollback blocked: target schema is absent, partial, or modified.';
    END IF;

    SELECT COUNT(*) INTO metadata_rows FROM school_sale_book_details;
    IF metadata_rows <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A rollback blocked: Book metadata rows exist; preserve production data.';
    END IF;
END//
DELIMITER ;

CALL phase5d_2ha_assert_book_rollback_safe();
DROP PROCEDURE phase5d_2ha_assert_book_rollback_safe;

DROP TABLE school_sale_book_details;

