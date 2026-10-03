-- Phase 4B production catalog reconciliation validation (READ ONLY).
-- Target: Hostforge production Payment DB hf_db_bfim0j6t.

SELECT 'category identities preserved' AS check_name,
       CASE WHEN COUNT(*) = 4 AND SUM(
           (sale_category_id = 1 AND category_code = 'UNIFORM' AND category_name = 'Uniform')
           OR (sale_category_id = 2 AND category_code = 'BOOKS' AND category_name = 'Books')
           OR (sale_category_id = 3 AND category_code = 'GRADUATION' AND category_name = 'Graduation')
           OR (sale_category_id = 4 AND category_code = 'MISCELLANEOUS' AND category_name = 'Miscellaneous')
       ) = 4 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_rows
FROM school_sale_categories;

SELECT 'foundation tables present' AS check_name,
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'obsolete unit_price removed' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND column_name = 'unit_price';

SELECT 'item applicability mode present' AS check_name,
       CASE WHEN COUNT(*) = 1
                  AND MAX(column_type) = "enum('ALL','RESTRICTED')"
            THEN 'PASS' ELSE 'FAIL' END AS result,
       MAX(column_type) AS observed_definition
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND column_name = 'applicability_mode';

SELECT 'required catalog foreign keys' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(constraint_name ORDER BY constraint_name) AS observed_constraints
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND constraint_type = 'FOREIGN KEY'
  AND constraint_name IN ('fk_school_sale_item_category', 'fk_school_sale_item_type');

SELECT 'no sellable Phase 4 items' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS active_item_rows
FROM school_sale_items
WHERE status = 'Active';

SELECT 'no catalog rows introduced' AS check_name,
       CASE WHEN
           (SELECT COUNT(*) FROM school_sale_item_types) = 0
           AND (SELECT COUNT(*) FROM school_sale_items) = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       (SELECT COUNT(*) FROM school_sale_item_types) AS item_type_rows,
       (SELECT COUNT(*) FROM school_sale_items) AS item_rows;

SELECT 'legacy cash and receipt data unchanged' AS check_name,
       CASE WHEN
           (SELECT COUNT(*) FROM cash_sales) = 0
           AND (SELECT COUNT(*) FROM cash_sale_items) = 0
           AND (SELECT COUNT(*) FROM cashier_receipt_print_events) = 0
           AND (SELECT COUNT(*) FROM official_receipt_sequences) = 1
       THEN 'PASS' ELSE 'FAIL' END AS result,
       (SELECT COUNT(*) FROM cash_sales) AS cash_sales_rows,
       (SELECT COUNT(*) FROM cash_sale_items) AS cash_sale_item_rows,
       (SELECT COUNT(*) FROM cashier_receipt_print_events) AS print_event_rows,
       (SELECT COUNT(*) FROM official_receipt_sequences) AS receipt_sequence_rows;

SELECT 'Phase 5 catalog tables remain excluded' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices', 'school_sale_item_applicability');

SELECT 'fee domain still present and out of scope' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('fees', 'fee_categories');

