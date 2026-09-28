-- Rollback for add_unique_fee_version_term.sql only.
-- This removes the database race-condition guard; application validation remains.
ALTER TABLE fee_versions
  DROP INDEX uq_fee_versions_term;
