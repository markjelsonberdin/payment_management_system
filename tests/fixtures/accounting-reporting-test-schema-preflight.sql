-- TEST DATABASE ONLY - READ-ONLY PREFLIGHT
-- DO NOT RUN AGAINST PRODUCTION
-- TARGET DATABASE NAME MUST END IN _test

SELECT
    DATABASE() AS selected_database,
    CASE
        WHEN DATABASE() IS NOT NULL
         AND LOWER(DATABASE()) <> 'payment_db'
         AND LOWER(DATABASE()) LIKE '%\_test'
        THEN 'PASS'
        ELSE 'FAIL'
    END AS database_name_guard;

SELECT
    required.table_name,
    COALESCE(actual.engine, 'MISSING') AS engine,
    CASE WHEN actual.table_name IS NULL THEN 'MISSING'
         WHEN UPPER(actual.engine) <> 'INNODB' THEN 'NON_TRANSACTIONAL'
         ELSE 'PASS'
    END AS result
FROM (
    SELECT 'students' AS table_name UNION ALL
    SELECT 'billing' UNION ALL
    SELECT 'billing_items' UNION ALL
    SELECT 'fees' UNION ALL
    SELECT 'fee_categories' UNION ALL
    SELECT 'payments' UNION ALL
    SELECT 'payment_allocations'
) required
LEFT JOIN information_schema.tables actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = required.table_name
ORDER BY required.table_name;

SELECT
    required.table_name,
    required.column_name,
    COALESCE(actual.column_type, 'MISSING') AS actual_type,
    CASE WHEN actual.column_name IS NULL THEN 'MISSING' ELSE 'PASS' END AS result
FROM (
    SELECT 'students' table_name, 'student_id' column_name UNION ALL
    SELECT 'students', 'user_id' UNION ALL
    SELECT 'students', 'student_number' UNION ALL
    SELECT 'students', 'full_name' UNION ALL
    SELECT 'students', 'course' UNION ALL
    SELECT 'students', 'year_level' UNION ALL
    SELECT 'students', 'status' UNION ALL
    SELECT 'billing', 'billing_id' UNION ALL
    SELECT 'billing', 'student_id' UNION ALL
    SELECT 'billing', 'billing_type' UNION ALL
    SELECT 'billing', 'academic_year' UNION ALL
    SELECT 'billing', 'semester' UNION ALL
    SELECT 'billing', 'total_amount' UNION ALL
    SELECT 'billing', 'discount_amount' UNION ALL
    SELECT 'billing', 'remaining_balance' UNION ALL
    SELECT 'billing', 'billing_status' UNION ALL
    SELECT 'billing_items', 'billing_item_id' UNION ALL
    SELECT 'billing_items', 'billing_id' UNION ALL
    SELECT 'billing_items', 'fee_id' UNION ALL
    SELECT 'billing_items', 'fee_name' UNION ALL
    SELECT 'billing_items', 'source_context' UNION ALL
    SELECT 'billing_items', 'amount' UNION ALL
    SELECT 'billing_items', 'paid_amount' UNION ALL
    SELECT 'billing_items', 'remaining_amount' UNION ALL
    SELECT 'billing_items', 'status' UNION ALL
    SELECT 'fees', 'fee_id' UNION ALL
    SELECT 'fees', 'fee_code' UNION ALL
    SELECT 'fees', 'category_id' UNION ALL
    SELECT 'fees', 'fee_name' UNION ALL
    SELECT 'fees', 'default_amount' UNION ALL
    SELECT 'fees', 'is_required' UNION ALL
    SELECT 'fees', 'status' UNION ALL
    SELECT 'fees', 'identity_status' UNION ALL
    SELECT 'fee_categories', 'category_id' UNION ALL
    SELECT 'fee_categories', 'category_name' UNION ALL
    SELECT 'fee_categories', 'priority_order' UNION ALL
    SELECT 'fee_categories', 'status' UNION ALL
    SELECT 'payments', 'payment_id' UNION ALL
    SELECT 'payments', 'student_id' UNION ALL
    SELECT 'payments', 'billing_id' UNION ALL
    SELECT 'payments', 'verified_by' UNION ALL
    SELECT 'payments', 'transaction_type' UNION ALL
    SELECT 'payments', 'payment_method' UNION ALL
    SELECT 'payments', 'amount' UNION ALL
    SELECT 'payments', 'payment_channel' UNION ALL
    SELECT 'payments', 'gateway_environment' UNION ALL
    SELECT 'payments', 'reference_number' UNION ALL
    SELECT 'payments', 'receipt_number' UNION ALL
    SELECT 'payments', 'payment_status' UNION ALL
    SELECT 'payments', 'payment_date' UNION ALL
    SELECT 'payments', 'verified_at' UNION ALL
    SELECT 'payments', 'created_at' UNION ALL
    SELECT 'payment_allocations', 'allocation_id' UNION ALL
    SELECT 'payment_allocations', 'payment_id' UNION ALL
    SELECT 'payment_allocations', 'billing_item_id' UNION ALL
    SELECT 'payment_allocations', 'allocated_amount'
) required
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = required.table_name
 AND actual.column_name = required.column_name
ORDER BY required.table_name, required.column_name;
