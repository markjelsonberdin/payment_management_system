-- Phase 4B: post-migration validation (READ ONLY)
-- Run after the core audit extension and catalog foundation migration.

SELECT 'catalog foundation tables' AS check_name,
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS found_tables
FROM information_schema.tables
WHERE table_schema = 'payment_db_test'
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'obsolete mutable unit_price excluded' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.columns
WHERE table_schema = 'payment_db_test' AND table_name = 'school_sale_items'
  AND column_name = 'unit_price';

SELECT 'item applicability mode present' AS check_name,
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result,
       MAX(column_type) AS column_definition
FROM information_schema.columns
WHERE table_schema = 'payment_db_test' AND table_name = 'school_sale_items'
  AND column_name = 'applicability_mode';

SELECT 'required catalog foreign keys' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(constraint_name ORDER BY constraint_name) AS found_constraints
FROM information_schema.table_constraints
WHERE constraint_schema = 'payment_db_test' AND table_name = 'school_sale_items'
  AND constraint_type = 'FOREIGN KEY'
  AND constraint_name IN ('fk_school_sale_item_category', 'fk_school_sale_item_type');

SELECT 'catalog item indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 4 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) AS found_indexes
FROM information_schema.statistics
WHERE table_schema = 'payment_db_test' AND table_name = 'school_sale_items'
  AND index_name IN ('uq_school_sale_item_code', 'idx_school_sale_item_category_status',
                     'idx_school_sale_item_type_status', 'idx_school_sale_item_name');

SELECT 'no sellable Phase 4 items' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL - Active catalog items are forbidden before Phase 5' END AS result,
       COUNT(*) AS active_item_count
FROM payment_db_test.school_sale_items
WHERE status = 'Active';

SELECT 'Phase 5+ tables remain excluded' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL - out-of-scope tables found' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS found_tables
FROM information_schema.tables
WHERE table_schema = 'payment_db_test'
  AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                     'school_sale_item_applicability', 'cash_sales', 'cash_sale_items',
                     'official_receipt_sequences', 'cashier_receipt_print_events');

SELECT 'core audit extension columns' AS check_name,
       CASE WHEN COUNT(*) = 5 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(column_name ORDER BY column_name) AS found_columns
FROM information_schema.columns
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs'
  AND column_name IN ('entity_type', 'entity_id', 'before_state', 'after_state', 'correlation_id');

SELECT 'core audit extension indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) AS found_indexes
FROM information_schema.statistics
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs'
  AND index_name IN ('idx_logs_entity', 'idx_logs_module_action_created', 'idx_logs_correlation');

SELECT 'fee domain unchanged' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.tables
WHERE table_schema = 'payment_db_test' AND table_name IN ('fees', 'fee_categories');
