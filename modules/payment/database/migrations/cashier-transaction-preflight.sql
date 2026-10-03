-- C4 Cashier Transaction Upgrade preflight (READ ONLY).
-- This script is intentionally non-mutating. It does not create, alter, or delete anything.
-- payment.sql is a schema reference only and must never be executed as this migration.

SELECT 'C4 target database' AS check_name, DATABASE() AS observed_value;
SELECT 'C4 MariaDB version' AS check_name, VERSION() AS observed_value;

SELECT 'Accounting Admin catalog foundation' AS check_name,
       CASE WHEN COUNT(*) = 7 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_tables,
       'Required: school_sale_categories, school_sale_item_types, school_sale_items, school_sale_item_variants, school_sale_variant_prices, school_sale_item_applicability, school_sale_book_details' AS requirement
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items', 'school_sale_item_variants', 'school_sale_variant_prices', 'school_sale_item_applicability', 'school_sale_book_details');

SELECT 'Payment financial dependencies' AS check_name,
       CASE WHEN COUNT(*) = 6 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_tables,
       'Required: students, payments, payment_allocations, billing, billing_items, payment_audit_outbox' AS requirement
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('students', 'payments', 'payment_allocations', 'billing', 'billing_items', 'payment_audit_outbox');

SELECT 'Cashier baseline state' AS check_name,
       CASE
           WHEN COUNT(*) = 0 THEN 'PASS: baseline absent; C4 may create Cashier-owned baseline tables'
           WHEN COUNT(*) = 4 THEN 'PASS: baseline present; C4 may extend it'
           ELSE 'FAIL: partial Cashier baseline'
       END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('official_receipt_sequences', 'cash_sales', 'cash_sale_items', 'cashier_receipt_print_events');

SELECT 'C4 partial deployment state' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_tables,
       'No C4 table may exist before a first C4 migration run.' AS requirement
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('cashier_transactions', 'cashier_transaction_fee_payments', 'cashier_sale_voids', 'cash_sale_line_reversals');

SELECT 'Catalog key columns' AS check_name,
       CASE WHEN COUNT(*) = 7 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name) ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_columns,
       'Expected identifiers: category, type, item, variant, price, applicability, and book detail primary keys.' AS requirement
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (table_name, column_name) IN (
      ('school_sale_categories', 'sale_category_id'),
      ('school_sale_item_types', 'sale_item_type_id'),
      ('school_sale_items', 'sale_item_id'),
      ('school_sale_item_variants', 'sale_variant_id'),
      ('school_sale_variant_prices', 'sale_variant_price_id'),
      ('school_sale_item_applicability', 'sale_item_applicability_id'),
      ('school_sale_book_details', 'sale_book_detail_id')
  );

SELECT 'Payment and outbox key columns' AS check_name,
       CASE WHEN COUNT(*) = 7 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name) ORDER BY table_name SEPARATOR ', '), '(none)') AS observed_columns,
       'Expected identifiers: student_id, payment_id, allocation_id, billing_id, billing_item_id, audit_outbox_id, correlation_id.' AS requirement
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (table_name, column_name) IN (
      ('students', 'student_id'), ('payments', 'payment_id'), ('payment_allocations', 'allocation_id'),
      ('billing', 'billing_id'), ('billing_items', 'billing_item_id'),
      ('payment_audit_outbox', 'audit_outbox_id'), ('payment_audit_outbox', 'correlation_id')
  );

SELECT 'Dependency identifier types' AS check_name,
       CASE WHEN COUNT(*) = 14 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name, '=', column_type) ORDER BY table_name, column_name SEPARATOR '; '), '(none)') AS observed_types,
       'Catalog and Payment identifiers must match the payment.sql target types.' AS requirement
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'school_sale_categories' AND column_name = 'sale_category_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'school_sale_item_types' AND column_name = 'sale_item_type_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'school_sale_items' AND column_name = 'sale_item_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'school_sale_item_variants' AND column_name = 'sale_variant_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'school_sale_variant_prices' AND column_name = 'sale_variant_price_id' AND column_type = 'bigint(20) unsigned')
    OR (table_name = 'school_sale_item_applicability' AND column_name = 'sale_item_applicability_id' AND column_type = 'bigint(20) unsigned')
    OR (table_name = 'school_sale_book_details' AND column_name = 'sale_book_detail_id' AND column_type = 'bigint(20) unsigned')
    OR (table_name = 'students' AND column_name = 'student_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'payments' AND column_name = 'payment_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'payment_allocations' AND column_name = 'allocation_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'billing' AND column_name = 'billing_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'billing_items' AND column_name = 'billing_item_id' AND column_type = 'int(10) unsigned')
    OR (table_name = 'payment_audit_outbox' AND column_name = 'audit_outbox_id' AND column_type = 'bigint(20) unsigned')
    OR (table_name = 'payment_audit_outbox' AND column_name = 'correlation_id' AND column_type = 'char(36)'));

SELECT 'Catalog foreign-key inventory' AS check_name,
       CASE WHEN COUNT(*) = 6 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name, '->', referenced_table_name, '.', referenced_column_name) ORDER BY table_name, column_name SEPARATOR '; '), '(none)') AS observed_foreign_keys,
       'Catalog must preserve its target six foreign-key relationships.' AS requirement
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL
  AND table_name IN ('school_sale_book_details', 'school_sale_item_applicability', 'school_sale_item_variants', 'school_sale_items', 'school_sale_variant_prices');

SELECT 'Baseline Cashier FK/index inventory when present' AS check_name,
       CASE
           WHEN NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cash_sales') THEN 'PASS: baseline absent; C4 will create it'
           WHEN (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL AND table_name IN ('cash_sales', 'cash_sale_items')) = 2
                AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cash_sales' AND index_name IN ('uq_cash_sale_receipt', 'idx_cash_sale_cashier_date', 'idx_cash_sale_student_date')) = 3 THEN 'PASS'
           ELSE 'FAIL'
       END AS result;

SELECT 'Existing global correlation ownership' AS check_name,
       CASE WHEN COUNT(*) = 1 AND MIN(non_unique) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(index_name ORDER BY index_name), '(none)') AS observed_indexes,
       'payment_audit_outbox must retain one unique correlation_id index; C4 does not duplicate global uniqueness.' AS requirement
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'payment_audit_outbox'
  AND column_name = 'correlation_id';

SELECT 'Baseline cash_sales compatibility when present' AS check_name,
       CASE
           WHEN NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cash_sales') THEN 'PASS: absent baseline is creatable'
           WHEN (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cash_sales' AND column_name IN ('cash_sale_id', 'student_id', 'cashier_id', 'receipt_number', 'total_amount', 'cash_received', 'change_amount', 'sale_status')) = 8 THEN 'PASS'
           ELSE 'FAIL'
       END AS result;

SELECT 'C4 eligibility' AS check_name,
       CASE
           WHEN (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items', 'school_sale_item_variants', 'school_sale_variant_prices', 'school_sale_item_applicability', 'school_sale_book_details')) <> 7 THEN 'FAIL: accounting catalog foundation missing'
           WHEN (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('students', 'payments', 'payment_allocations', 'billing', 'billing_items', 'payment_audit_outbox')) <> 6 THEN 'FAIL: payment dependencies missing'
           WHEN (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'school_sale_categories' AND column_name = 'sale_category_id' AND column_type = 'int(10) unsigned') OR (table_name = 'school_sale_item_types' AND column_name = 'sale_item_type_id' AND column_type = 'int(10) unsigned') OR (table_name = 'school_sale_items' AND column_name = 'sale_item_id' AND column_type = 'int(10) unsigned') OR (table_name = 'school_sale_item_variants' AND column_name = 'sale_variant_id' AND column_type = 'int(10) unsigned') OR (table_name = 'school_sale_variant_prices' AND column_name = 'sale_variant_price_id' AND column_type = 'bigint(20) unsigned') OR (table_name = 'school_sale_item_applicability' AND column_name = 'sale_item_applicability_id' AND column_type = 'bigint(20) unsigned') OR (table_name = 'school_sale_book_details' AND column_name = 'sale_book_detail_id' AND column_type = 'bigint(20) unsigned') OR (table_name = 'students' AND column_name = 'student_id' AND column_type = 'int(10) unsigned') OR (table_name = 'payments' AND column_name = 'payment_id' AND column_type = 'int(10) unsigned') OR (table_name = 'payment_allocations' AND column_name = 'allocation_id' AND column_type = 'int(10) unsigned') OR (table_name = 'billing' AND column_name = 'billing_id' AND column_type = 'int(10) unsigned') OR (table_name = 'billing_items' AND column_name = 'billing_item_id' AND column_type = 'int(10) unsigned') OR (table_name = 'payment_audit_outbox' AND column_name = 'audit_outbox_id' AND column_type = 'bigint(20) unsigned') OR (table_name = 'payment_audit_outbox' AND column_name = 'correlation_id' AND column_type = 'char(36)'))) <> 14 THEN 'FAIL: dependency identifier types are incompatible'
           WHEN (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL AND table_name IN ('school_sale_book_details', 'school_sale_item_applicability', 'school_sale_item_variants', 'school_sale_items', 'school_sale_variant_prices')) <> 6 THEN 'FAIL: catalog foreign keys are incompatible'
           WHEN (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('official_receipt_sequences', 'cash_sales', 'cash_sale_items', 'cashier_receipt_print_events')) NOT IN (0, 4) THEN 'FAIL: Cashier baseline is partial'
           WHEN (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cashier_transactions', 'cashier_transaction_fee_payments', 'cashier_sale_voids', 'cash_sale_line_reversals')) <> 0 THEN 'FAIL: C4 appears partially deployed'
           ELSE 'PASS: eligible for approved C4 migration'
       END AS result;
