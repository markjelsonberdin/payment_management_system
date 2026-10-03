-- Phase 5 approval UI audit fields. Apply to payment_db and payment_db_test.
-- Additive and safe to rerun on MariaDB versions supporting IF NOT EXISTS.
ALTER TABLE billing_runs
  ADD COLUMN IF NOT EXISTS initiated_by_name_snapshot VARCHAR(255) NULL AFTER initiated_by,
  ADD COLUMN IF NOT EXISTS approved_by_name_snapshot VARCHAR(255) NULL AFTER approved_by;
