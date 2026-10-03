-- Phase 5D-2H-A Book metadata schema preflight (READ ONLY).
-- Target: Hostforge Payment DB hf_db_bfim0j6t on MariaDB 11.8.8.
-- Do not add USE: connect directly to the intended production database.

SELECT 'production target' AS check_name,
       CASE WHEN DATABASE() = 'hf_db_bfim0j6t' THEN 'PASS' ELSE 'FAIL' END AS result,
       DATABASE() AS observed_value;

SELECT 'MariaDB production version' AS check_name,
       CASE WHEN VERSION() LIKE '11.8.8-MariaDB%' THEN 'PASS' ELSE 'FAIL' END AS result,
       VERSION() AS observed_value;

SELECT 'school_sale_items parent exists' AS check_name,
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'school_sale_items';

SELECT 'parent storage conventions' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(engine) = 'InnoDB'
                  AND MAX(table_collation) = 'utf8mb4_uca1400_ai_ci'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, ':', engine, ':', table_collation)) AS observed_value
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'school_sale_items';

SELECT 'parent PK datatype' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(column_type) = 'int(10) unsigned'
                  AND MAX(is_nullable) = 'NO'
                  AND MAX(column_key) = 'PRI'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(column_name, ':', column_type, ':', is_nullable, ':', column_key)) AS observed_value
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND column_name = 'sale_item_id';

SELECT 'controlled BOOK item type' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(type_name) = 'Book'
                  AND MAX(metadata_profile) = 'BOOK_REQUIRED'
                  AND MAX(status) = 'Active'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(type_code, ':', type_name, ':', metadata_profile, ':', status)) AS observed_value
FROM school_sale_item_types
WHERE type_code = 'BOOK';

SELECT 'conflicting Book tables absent' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_books', 'school_sale_item_book_details',
                     'school_sales_book_details', 'school_sale_book_metadata');

SELECT 'conflicting Book columns absent from item table' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(column_name ORDER BY ordinal_position), '(none)') AS observed_columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'school_sale_items'
  AND column_name IN ('book_title', 'author', 'publisher', 'edition', 'isbn',
                      'book_notes', 'book_metadata');

-- FRESH is the only state in which the migration should be executed.
-- EXACT_PRESENT means the final schema is already installed; validate it and do not rerun.
-- CONFLICTING means a partial or different target table exists; stop without repair.
SELECT 'Book metadata deployment state' AS check_name,
       CASE
         WHEN (SELECT COUNT(*) FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details') = 0
           THEN 'FRESH'
         WHEN (SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details') = 12
          AND (SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND ((column_name = 'sale_book_detail_id' AND column_type = 'bigint(20) unsigned' AND column_key = 'PRI' AND extra = 'auto_increment')
                   OR (column_name = 'sale_item_id' AND column_type = 'int(10) unsigned' AND is_nullable = 'NO')
                   OR (column_name = 'book_title' AND column_type = 'varchar(255)' AND is_nullable = 'NO')
                   OR (column_name = 'author' AND column_type = 'varchar(255)' AND is_nullable = 'YES')
                   OR (column_name = 'publisher' AND column_type = 'varchar(255)' AND is_nullable = 'YES')
                   OR (column_name = 'edition' AND column_type = 'varchar(100)' AND is_nullable = 'YES')
                   OR (column_name = 'isbn' AND column_type = 'varchar(32)' AND is_nullable = 'YES')
                   OR (column_name = 'notes' AND data_type = 'text' AND is_nullable = 'YES')
                   OR (column_name IN ('created_by','updated_by') AND column_type = 'int(10) unsigned' AND is_nullable = 'YES')
                   OR (column_name IN ('created_at','updated_at') AND data_type = 'timestamp' AND is_nullable = 'NO'))) = 12
          AND (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND index_name = 'uq_school_sale_book_item' AND non_unique = 0) = 1
          AND (SELECT COUNT(*) FROM information_schema.referential_constraints
               WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND constraint_name = 'fk_school_sale_book_item'
                 AND referenced_table_name = 'school_sale_items'
                 AND delete_rule = 'RESTRICT' AND update_rule = 'RESTRICT') = 1
          AND (SELECT COUNT(*) FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND engine = 'InnoDB' AND table_collation = 'utf8mb4_uca1400_ai_ci') = 1
          AND (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND index_name = 'PRIMARY' AND column_name = 'sale_book_detail_id'
                 AND non_unique = 0) = 1
          AND (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND index_name = 'idx_school_sale_book_isbn' AND column_name = 'isbn'
                 AND non_unique = 1) = 1
          AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details') = 3
          AND (SELECT COUNT(*) FROM information_schema.table_constraints
               WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND constraint_type = 'CHECK'
                 AND constraint_name IN ('chk_school_sale_book_title_nonblank',
                     'chk_school_sale_book_author_nonblank', 'chk_school_sale_book_publisher_nonblank',
                     'chk_school_sale_book_edition_nonblank', 'chk_school_sale_book_isbn_format',
                     'chk_school_sale_book_notes_nonblank')) = 6
          AND (SELECT COUNT(*) FROM information_schema.table_constraints
               WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
                 AND constraint_type = 'CHECK') = 6
           THEN 'EXACT_PRESENT'
         ELSE 'CONFLICTING'
       END AS result,
       (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details') AS observed_columns;

