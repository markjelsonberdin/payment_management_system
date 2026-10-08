-- Batch 4J rollback. Dropping checks does not reverse or repair data written while active.
ALTER TABLE billing_items
  DROP CONSTRAINT chk_billing_items_remaining_consistent,
  DROP CONSTRAINT chk_billing_items_paid_not_over_amount,
  DROP CONSTRAINT chk_billing_items_nonnegative_values;

ALTER TABLE payment_allocations
  DROP CONSTRAINT chk_payment_allocations_positive_amount;

ALTER TABLE payments
  DROP CONSTRAINT chk_payments_positive_amount;
