-- Phase 4B production reconciliation preflight (READ ONLY).
-- Target: Hostforge production Payment DB hf_db_bfim0j6t.
-- This script makes no schema or data changes.

SELECT 'active database' AS check_name,
       CASE WHEN DATABASE() = 'hf_db_bfim0j6t' THEN 'PASS' ELSE 'FAIL' END AS result,
       DATABASE() AS observed_value;

SELECT 'legacy catalog tables' AS check_name,
       CASE WHEN
           SUM(table_name = 'school_sale_categories') = 1
           AND SUM(table_name = 'school_sale_items') = 1
           AND SUM(table_name = 'school_sale_item_types') = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'expected legacy categories' AS check_name,
       CASE WHEN COUNT(*) = 4 AND SUM(
           (sale_category_id = 1 AND category_name = 'Uniform' AND status = 'Active' AND sort_order = 10)
           OR (sale_category_id = 2 AND category_name = 'Books' AND status = 'Active' AND sort_order = 20)
           OR (sale_category_id = 3 AND category_name = 'Graduation' AND status = 'Active' AND sort_order = 30)
           OR (sale_category_id = 4 AND category_name = 'Miscellaneous' AND status = 'Active' AND sort_order = 40)
       ) = 4 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_rows
FROM school_sale_categories;

SELECT 'legacy items empty' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_rows
FROM school_sale_items;

SELECT 'legacy mutable price present' AS check_name,
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND column_name = 'unit_price'
  AND column_type = 'decimal(10,2)';

SELECT 'expected legacy keys' AS check_name,
       CASE WHEN
           SUM(table_name = 'school_sale_categories'
               AND constraint_name = 'uq_school_sale_category_name'
               AND constraint_type = 'UNIQUE') = 1
           AND SUM(table_name = 'school_sale_items'
               AND constraint_name = 'fk_sale_item_category'
               AND constraint_type = 'FOREIGN KEY') = 1
       THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, '.', constraint_name) ORDER BY table_name, constraint_name) AS observed_constraints
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE()
  AND (
      (table_name = 'school_sale_categories' AND constraint_name = 'uq_school_sale_category_name')
      OR (table_name = 'school_sale_items' AND constraint_name = 'fk_sale_item_category')
  );

SELECT 'expected legacy item index' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 1 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(DISTINCT index_name) AS observed_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_items'
  AND index_name = 'idx_sale_item_category_status';

SELECT 'target columns not partially deployed' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name) ORDER BY table_name, ordinal_position), '(none)') AS observed_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'school_sale_categories'
       AND column_name IN ('category_code', 'created_by', 'updated_by', 'updated_at'))
      OR
      (table_name = 'school_sale_items'
       AND column_name IN ('item_code', 'sale_item_type_id', 'description', 'applicability_mode', 'created_by', 'updated_by'))
  );

SELECT 'sales remain empty' AS check_name,
       CASE WHEN
           (SELECT COUNT(*) FROM cash_sales) = 0
           AND (SELECT COUNT(*) FROM cash_sale_items) = 0
           AND (SELECT COUNT(*) FROM cashier_receipt_print_events) = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       (SELECT COUNT(*) FROM cash_sales) AS cash_sales_rows,
       (SELECT COUNT(*) FROM cash_sale_items) AS cash_sale_item_rows,
       (SELECT COUNT(*) FROM cashier_receipt_print_events) AS print_event_rows;

SELECT 'receipt sequence preserved' AS check_name,
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_rows
FROM official_receipt_sequences;

SELECT 'fee domain present and out of scope' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('fees', 'fee_categories');

