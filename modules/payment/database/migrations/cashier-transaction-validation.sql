-- C4 Cashier Transaction Upgrade validation (READ ONLY).
-- Run after an approved C4 migration. It makes no schema or data changes.

SELECT 'C4 tables present' AS check_name,
       CASE WHEN COUNT(*) = 4 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(table_name ORDER BY table_name), '(none)') AS observed_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('cashier_transactions', 'cashier_transaction_fee_payments', 'cashier_sale_voids', 'cash_sale_line_reversals');

SELECT 'C4 transaction uniqueness indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(DISTINCT index_name ORDER BY index_name), '(none)') AS observed_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'cashier_transactions'
  AND index_name IN ('uq_cashier_transaction_number', 'uq_cashier_transaction_receipt', 'uq_cashier_transaction_idempotency');

SELECT 'C4 fee linkage uniqueness indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 2 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(DISTINCT index_name ORDER BY index_name), '(none)') AS observed_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'cashier_transaction_fee_payments'
  AND index_name IN ('uq_cashier_transaction_fee_payment_transaction', 'uq_cashier_transaction_fee_payment_payment');

SELECT 'C4 Quick Void uniqueness indexes' AS check_name,
       CASE WHEN COUNT(DISTINCT index_name) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(DISTINCT index_name ORDER BY index_name), '(none)') AS observed_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ('cashier_sale_voids', 'cash_sale_line_reversals')
  AND index_name IN ('uq_cashier_sale_void_refund_slip', 'uq_cashier_sale_void_idempotency', 'uq_cash_sale_line_reversal_original_line');

SELECT 'C4 immutable catalog snapshot columns' AS check_name,
       CASE WHEN COUNT(*) = 20 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(column_name ORDER BY ordinal_position), '(none)') AS observed_columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'cash_sale_items'
  AND column_name IN ('sale_variant_id','sale_variant_price_id','item_code_snapshot','category_code_snapshot','item_type_code_snapshot','item_type_name_snapshot','variant_code_snapshot','variant_name_snapshot','size_label_snapshot','price_effective_from_snapshot','price_effective_to_snapshot','applicability_mode_snapshot','student_program_snapshot','student_year_level_snapshot','applicability_result_snapshot','book_title_snapshot','book_author_snapshot','book_publisher_snapshot','book_edition_snapshot','book_isbn_snapshot');

SELECT 'C4 legacy snapshot compatibility' AS check_name,
       CASE WHEN COUNT(*) = 20 AND SUM(is_nullable = 'YES') = 20 THEN 'PASS' ELSE 'FAIL' END AS result,
       'All C4 cash_sale_items columns remain nullable for existing legacy lines.' AS requirement
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'cash_sale_items'
  AND column_name IN ('sale_variant_id','sale_variant_price_id','item_code_snapshot','category_code_snapshot','item_type_code_snapshot','item_type_name_snapshot','variant_code_snapshot','variant_name_snapshot','size_label_snapshot','price_effective_from_snapshot','price_effective_to_snapshot','applicability_mode_snapshot','student_program_snapshot','student_year_level_snapshot','applicability_result_snapshot','book_title_snapshot','book_author_snapshot','book_publisher_snapshot','book_edition_snapshot','book_isbn_snapshot');

SELECT 'C4 correlation indexing without duplicate global uniqueness' AS check_name,
       CASE WHEN (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cashier_transactions' AND index_name = 'idx_cashier_transaction_correlation' AND non_unique = 1) = 1
                 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cashier_sale_voids' AND index_name = 'idx_cashier_sale_void_correlation' AND non_unique = 1) = 1
                 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payment_audit_outbox' AND column_name = 'correlation_id' AND non_unique = 0) = 1
            THEN 'PASS' ELSE 'FAIL' END AS result;

SELECT 'C4 required foreign keys' AS check_name,
       CASE WHEN COUNT(*) = 13 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', constraint_name) ORDER BY table_name, constraint_name), '(none)') AS observed_constraints
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'
  AND constraint_name IN ('fk_cashier_transaction_student','fk_cashier_transaction_fee_payment_header','fk_cashier_transaction_fee_payment_payment','fk_cash_sale_cashier_transaction','fk_cash_sale_line_variant','fk_cash_sale_line_price','fk_cashier_sale_void_transaction','fk_cashier_sale_void_sale','fk_cash_sale_line_reversal_void','fk_cash_sale_line_reversal_line','fk_cash_sale_line_reversal_sale','fk_receipt_print_transaction','fk_receipt_print_void');

SELECT 'C4 Quick Void structural boundary' AS check_name,
       CASE WHEN (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cash_sale_line_reversals' AND column_name IN ('original_cash_sale_line_id','quantity_snapshot','refunded_line_total')) = 3
                 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cash_sale_line_reversals' AND index_name = 'uq_cash_sale_line_reversal_original_line' AND non_unique = 0) = 1
            THEN 'PASS' ELSE 'FAIL' END AS result,
       'One immutable reversal can reference one complete original sale line; no partial-quantity field exists.' AS requirement;

SELECT 'C4 does not alter financial source tables' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COALESCE(GROUP_CONCAT(CONCAT(table_name, '.', column_name) ORDER BY table_name, column_name), '(none)') AS unexpected_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('payments','payment_allocations','billing','billing_items')
  AND column_name IN ('cashier_transaction_id','cashier_sale_void_id','cash_sale_line_reversal_id');
