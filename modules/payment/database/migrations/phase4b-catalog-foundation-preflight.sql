-- Phase 4B: catalog foundation preflight (READ ONLY)
-- Do not run the Phase 4B migration unless every required check below reports PASS.
-- Scope: payment_db_test catalog foundations and sms2_db activity_logs audit extension only.

SELECT 'environment' AS check_group, DATABASE() AS connected_database, VERSION() AS server_version,
       @@default_storage_engine AS default_engine, @@character_set_database AS database_charset,
       @@collation_database AS database_collation, @@sql_mode AS sql_mode;

-- Run this file while connected with visibility to both payment_db_test and sms2_db.
SELECT 'payment_db_test required fee tables' AS check_name,
       CASE WHEN COUNT(*) = 4 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS found_tables
FROM information_schema.tables
WHERE table_schema = 'payment_db_test'
  AND table_name IN ('fees', 'fee_categories', 'fee_types', 'students');

SELECT 'Phase 4 catalog tables must be absent' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL - unexpected existing catalog state' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS found_tables
FROM information_schema.tables
WHERE table_schema = 'payment_db_test'
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'excluded sales and receipt tables remain absent' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL - separate deployment state exists' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS found_tables
FROM information_schema.tables
WHERE table_schema = 'payment_db_test'
  AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                     'school_sale_item_applicability', 'cash_sales', 'cash_sale_items',
                     'official_receipt_sequences', 'cashier_receipt_print_events');

SELECT 'fee domain remains out of scope' AS check_name,
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, ':', table_rows) ORDER BY table_name) AS table_state
FROM information_schema.tables
WHERE table_schema = 'payment_db_test' AND table_name IN ('fees', 'fee_categories');

SELECT 'activity_logs baseline table' AS check_name,
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.tables
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs';

SELECT 'activity_logs audit-extension columns must be absent' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL - extension or divergent state already exists' END AS result,
       COALESCE(GROUP_CONCAT(column_name ORDER BY column_name), '(none)') AS found_columns
FROM information_schema.columns
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs'
  AND column_name IN ('entity_type', 'entity_id', 'before_state', 'after_state', 'correlation_id');

SELECT 'activity_logs required baseline columns' AS check_name,
       CASE WHEN COUNT(*) = 10 THEN 'PASS' ELSE 'FAIL - unexpected audit schema' END AS result,
       GROUP_CONCAT(column_name ORDER BY ordinal_position) AS found_columns
FROM information_schema.columns
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs'
  AND column_name IN ('id', 'user_id', 'user_name', 'role_key', 'action', 'module_key',
                      'detail', 'ip_address', 'user_agent', 'created_at');

SELECT 'activity_logs required baseline indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 4 THEN 'PASS' ELSE 'FAIL - unexpected audit indexes' END AS result,
       GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) AS found_indexes
FROM information_schema.statistics
WHERE table_schema = 'sms2_db' AND table_name = 'activity_logs'
  AND index_name IN ('PRIMARY', 'idx_logs_user', 'idx_logs_action', 'idx_logs_created');

-- Manual execution gate: do not run the migration if any result above is FAIL.
