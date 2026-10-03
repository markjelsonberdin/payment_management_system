-- Phase 5B School Sales foundation preflight (READ ONLY).
-- Run on the Hostforge production Payment DB connection.
-- Authoritative target: hf_db_bfim0j6t on MariaDB 11.8.8.
-- Do not add USE: Hostforge's database proxy rejects explicit USE statements.

SELECT 'production target' AS check_name,
       CASE WHEN DATABASE() = 'hf_db_bfim0j6t' THEN 'PASS' ELSE 'FAIL' END AS result,
       DATABASE() AS observed_database;

SELECT 'MariaDB production version' AS check_name,
       CASE WHEN VERSION() LIKE '11.8.8-MariaDB%' THEN 'PASS' ELSE 'FAIL' END AS result,
       VERSION() AS observed_version;

SELECT 'required storage conventions' AS check_name,
       CASE WHEN COUNT(*) = 3
                  AND SUM(engine = 'InnoDB') = 3
                  AND SUM(table_collation = 'utf8mb4_uca1400_ai_ci') = 3
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, ':', engine, ':', table_collation)
                    ORDER BY table_name SEPARATOR ', ') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'Phase 4 foundation tables' AS check_name,
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items');

SELECT 'Phase 4 catalog row state' AS check_name,
       CASE WHEN
           (SELECT COUNT(*) FROM school_sale_categories) = 4
           AND (SELECT COUNT(*) FROM school_sale_items) = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       (SELECT COUNT(*) FROM school_sale_categories) AS category_rows,
       (SELECT COUNT(*) FROM school_sale_items) AS item_rows;

SELECT 'Phase 4 item FK datatypes' AS check_name,
       CASE WHEN COUNT(*) = 3
                  AND SUM(column_type = 'int(10) unsigned') = 3
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, '.', column_name, ':', column_type)
                    ORDER BY table_name, column_name SEPARATOR ', ') AS observed_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'school_sale_categories' AND column_name = 'sale_category_id')
      OR (table_name = 'school_sale_item_types' AND column_name = 'sale_item_type_id')
      OR (table_name = 'school_sale_items' AND column_name = 'sale_item_id')
  );

SELECT 'Phase 5 tables absent' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'school_sale_item_variants',
      'school_sale_variant_prices',
      'school_sale_item_applicability',
      'payment_audit_outbox'
  );

-- Required classification:
-- FRESH        = no item-type rows; safe to seed all six.
-- EXACT_SEEDED = exactly the six approved rows with exact controlled values.
-- CONFLICTING  = partial, additional, duplicate-semantic, or mismatched rows.
SELECT 'controlled item-type state' AS check_name,
       CASE
           WHEN COUNT(*) = 0 THEN 'FRESH'
           WHEN COUNT(*) = 6 AND SUM(
               (type_code = 'UNIFORM' AND type_name = 'Uniform' AND metadata_profile = 'UNIFORM_VARIANT' AND status = 'Active' AND sort_order = 10)
               OR (type_code = 'BOOK' AND type_name = 'Book' AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active' AND sort_order = 20)
               OR (type_code = 'LEARNING_MATERIAL' AND type_name = 'Learning Material' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 30)
               OR (type_code = 'ACCESSORY' AND type_name = 'Accessory' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 40)
               OR (type_code = 'GRADUATION_ITEM' AND type_name = 'Graduation Item' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 50)
               OR (type_code = 'OTHER_MERCHANDISE' AND type_name = 'Other Merchandise' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 60)
           ) = 6 THEN 'EXACT_SEEDED'
           ELSE 'CONFLICTING'
       END AS item_type_state,
       COUNT(*) AS observed_rows
FROM school_sale_item_types;

SELECT 'persistent generated-column capability evidence' AS check_name,
       CASE WHEN COUNT(*) >= 2 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS existing_persistent_generated_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND extra LIKE '%STORED GENERATED%';

SELECT 'CHECK-constraint metadata capability evidence' AS check_name,
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS existing_check_constraints
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE()
  AND constraint_type = 'CHECK';

SELECT 'protected domains remain present' AS check_name,
       CASE WHEN COUNT(*) = 8 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'fees', 'fee_categories', 'billing', 'payment_allocations',
      'cash_sales', 'cash_sale_items', 'official_receipt_sequences',
      'cashier_receipt_print_events'
  );

