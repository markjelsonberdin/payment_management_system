-- Batch 4J post-migration validation. Read only.
SELECT constraint_name, table_name, check_clause
FROM information_schema.check_constraints
WHERE constraint_schema = DATABASE()
  AND constraint_name IN (
    'chk_payments_positive_amount',
    'chk_payment_allocations_positive_amount',
    'chk_billing_items_nonnegative_values',
    'chk_billing_items_paid_not_over_amount',
    'chk_billing_items_remaining_consistent'
  )
ORDER BY table_name, constraint_name;

SELECT 'payments_nonpositive_amount' AS check_name, COUNT(*) AS violation_count
FROM payments WHERE amount <= 0
UNION ALL
SELECT 'allocation_nonpositive_amount', COUNT(*)
FROM payment_allocations WHERE allocated_amount <= 0
UNION ALL
SELECT 'billing_item_invalid_values', COUNT(*)
FROM billing_items
WHERE amount < 0 OR paid_amount < 0 OR remaining_amount < 0
   OR paid_amount > amount
   OR ABS(remaining_amount - (amount - paid_amount)) > 0.001;
