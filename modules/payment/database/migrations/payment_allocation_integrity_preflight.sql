-- Batch 4J read-only production preflight. Run and review every result before migration.
SELECT VERSION() AS mariadb_version;
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('payments','payment_allocations','billing','billing_items')
ORDER BY table_name;

SELECT 'payments_nonpositive_amount' AS check_name, COUNT(*) AS violation_count
FROM payments WHERE amount <= 0
UNION ALL
SELECT 'allocation_nonpositive_amount', COUNT(*)
FROM payment_allocations WHERE allocated_amount <= 0
UNION ALL
SELECT 'billing_item_negative_amount', COUNT(*)
FROM billing_items WHERE amount < 0
UNION ALL
SELECT 'billing_item_negative_paid', COUNT(*)
FROM billing_items WHERE paid_amount < 0
UNION ALL
SELECT 'billing_item_negative_remaining', COUNT(*)
FROM billing_items WHERE remaining_amount < 0
UNION ALL
SELECT 'billing_item_paid_over_amount', COUNT(*)
FROM billing_items WHERE paid_amount > amount
UNION ALL
SELECT 'billing_item_balance_mismatch', COUNT(*)
FROM billing_items WHERE ABS(remaining_amount - (amount - paid_amount)) > 0.001
UNION ALL
SELECT 'payment_overallocated', COUNT(*)
FROM (
    SELECT p.payment_id
    FROM payments p
    JOIN payment_allocations pa ON pa.payment_id = p.payment_id
    GROUP BY p.payment_id, p.amount
    HAVING SUM(pa.allocated_amount) > p.amount
) violations
UNION ALL
SELECT 'allocation_wrong_billing', COUNT(*)
FROM payment_allocations pa
JOIN payments p ON p.payment_id = pa.payment_id
JOIN billing_items bi ON bi.billing_item_id = pa.billing_item_id
WHERE bi.billing_id <> p.billing_id;

SELECT trigger_name, event_manipulation, action_timing
FROM information_schema.triggers
WHERE trigger_schema = DATABASE()
  AND event_object_table IN ('payment_allocations','billing_items')
ORDER BY event_object_table, trigger_name;

SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_order
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'payment_allocations'
GROUP BY index_name, non_unique
ORDER BY index_name;

SELECT payment_status, gateway_environment, COUNT(*) AS legacy_checkout_count
FROM payments
WHERE checkout_session_id IS NOT NULL AND payment_intent_id IS NULL
GROUP BY payment_status, gateway_environment
ORDER BY payment_status, gateway_environment;
