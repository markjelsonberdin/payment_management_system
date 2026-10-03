-- Phase 5D-2H-A Book metadata validation (READ ONLY).
-- Run on hf_db_bfim0j6t after the migration. No application behavior is proven here.

SELECT 'production target' AS check_name,
       CASE WHEN DATABASE() = 'hf_db_bfim0j6t' THEN 'PASS' ELSE 'FAIL' END AS result,
       DATABASE() AS observed_value;

SELECT 'Book detail table storage' AS check_name,
       CASE WHEN COUNT(*) = 1 AND MAX(engine) = 'InnoDB'
                  AND MAX(table_collation) = 'utf8mb4_uca1400_ai_ci'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(engine, ':', table_collation)) AS observed_value
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';

SELECT 'Book detail columns exact' AS check_name,
       CASE WHEN COUNT(*) = 12
          AND SUM(column_name = 'sale_book_detail_id' AND column_type = 'bigint(20) unsigned' AND is_nullable = 'NO' AND extra = 'auto_increment') = 1
          AND SUM(column_name = 'sale_item_id' AND column_type = 'int(10) unsigned' AND is_nullable = 'NO') = 1
          AND SUM(column_name = 'book_title' AND column_type = 'varchar(255)' AND is_nullable = 'NO') = 1
          AND SUM(column_name = 'author' AND column_type = 'varchar(255)' AND is_nullable = 'YES') = 1
          AND SUM(column_name = 'publisher' AND column_type = 'varchar(255)' AND is_nullable = 'YES') = 1
          AND SUM(column_name = 'edition' AND column_type = 'varchar(100)' AND is_nullable = 'YES') = 1
          AND SUM(column_name = 'isbn' AND column_type = 'varchar(32)' AND is_nullable = 'YES') = 1
          AND SUM(column_name = 'notes' AND data_type = 'text' AND is_nullable = 'YES') = 1
          AND SUM(column_name IN ('created_by','updated_by') AND column_type = 'int(10) unsigned' AND is_nullable = 'YES') = 2
          AND SUM(column_name IN ('created_at','updated_at') AND data_type = 'timestamp' AND is_nullable = 'NO') = 2
            THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';

SELECT 'Book detail PK and indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 3
          AND SUM(index_name = 'PRIMARY' AND column_name = 'sale_book_detail_id' AND non_unique = 0) = 1
          AND SUM(index_name = 'uq_school_sale_book_item' AND column_name = 'sale_item_id' AND non_unique = 0) = 1
          AND SUM(index_name = 'idx_school_sale_book_isbn' AND column_name = 'isbn' AND non_unique = 1) = 1
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(index_name, ':', column_name, ':', non_unique) ORDER BY index_name, seq_in_index) AS observed_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';

SELECT 'Book detail FK column mapping' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(column_name) = 'sale_item_id'
                  AND MAX(referenced_table_name) = 'school_sale_items'
                  AND MAX(referenced_column_name) = 'sale_item_id'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(column_name, '->', referenced_table_name, '.', referenced_column_name)) AS observed_mapping
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
  AND constraint_name = 'fk_school_sale_book_item';

SELECT 'Book detail restrictive FK' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(referenced_table_name) = 'school_sale_items'
                  AND MAX(delete_rule) = 'RESTRICT' AND MAX(update_rule) = 'RESTRICT'
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(constraint_name, ':', referenced_table_name, ':', delete_rule, ':', update_rule)) AS observed_fk
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
  AND constraint_name = 'fk_school_sale_book_item';

SELECT 'Book detail CHECK constraints' AS check_name,
       CASE WHEN COUNT(*) = 6
          AND SUM(constraint_name IN ('chk_school_sale_book_title_nonblank',
                  'chk_school_sale_book_author_nonblank', 'chk_school_sale_book_publisher_nonblank',
                  'chk_school_sale_book_edition_nonblank', 'chk_school_sale_book_isbn_format',
                  'chk_school_sale_book_notes_nonblank')) = 6
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(constraint_name ORDER BY constraint_name) AS observed_checks
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE() AND table_name = 'school_sale_book_details'
  AND constraint_type = 'CHECK';

SELECT 'Book detail starts empty' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_rows
FROM school_sale_book_details;

SELECT 'Book table contains descriptive fields only' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(column_name), '(none)') AS prohibited_columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
  AND column_name REGEXP '(price|amount|currency|stock|quantity|inventory|billing|assessment|debt|allocation|payment|receipt|cashier|sale_status)';

SELECT 'protected financial and Cashier domains remain present' AS check_name,
       CASE WHEN COUNT(DISTINCT table_name) = 8 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(DISTINCT table_name ORDER BY table_name) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('fees', 'billing', 'billing_items', 'payments',
                     'payment_allocations', 'cash_sales', 'cash_sale_items',
                     'cashier_receipt_print_events');

SELECT 'Book table has only approved FK dependency' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND SUM(referenced_table_name = 'school_sale_items') = 1
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(DISTINCT referenced_table_name) AS observed_dependencies
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details'
  AND referenced_table_name IS NOT NULL;

