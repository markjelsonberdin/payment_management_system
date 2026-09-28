-- Apply only after preflight_fee_version_term_conflicts.sql returns zero rows.
-- Archived combinations remain reserved and cannot be reused.
ALTER TABLE fee_versions
  ADD UNIQUE KEY uq_fee_versions_term (fee_id, academic_year, semester);
