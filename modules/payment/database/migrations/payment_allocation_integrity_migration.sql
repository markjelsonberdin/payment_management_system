-- Batch 4J additive constraints. DO NOT run until preflight returns zero violations.
-- ALTER TABLE obtains metadata locks and may rebuild tables depending on Hostforge MariaDB version.
ALTER TABLE payments
  ADD CONSTRAINT chk_payments_positive_amount CHECK (amount > 0);

ALTER TABLE payment_allocations
  ADD CONSTRAINT chk_payment_allocations_positive_amount CHECK (allocated_amount > 0);

ALTER TABLE billing_items
  ADD CONSTRAINT chk_billing_items_nonnegative_values
    CHECK (amount >= 0 AND paid_amount >= 0 AND remaining_amount >= 0),
  ADD CONSTRAINT chk_billing_items_paid_not_over_amount
    CHECK (paid_amount <= amount),
  ADD CONSTRAINT chk_billing_items_remaining_consistent
    CHECK (ABS(remaining_amount - (amount - paid_amount)) <= 0.001);
