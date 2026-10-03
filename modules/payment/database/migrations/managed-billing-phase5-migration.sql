-- Phase 5 managed bulk orchestration. PREPARE ONLY: do not execute without approval.
-- Apply only after the Phase 2 managed billing schema is present and the target
-- preflight confirms these columns do not already exist.
ALTER TABLE billing_run_assignments
  ADD COLUMN student_number VARCHAR(50) NOT NULL AFTER student_id,
  ADD COLUMN student_full_name VARCHAR(150) NOT NULL AFTER student_number,
  ADD COLUMN program_code VARCHAR(100) NULL AFTER student_full_name,
  ADD COLUMN year_level VARCHAR(20) NULL AFTER program_code,
  ADD COLUMN enrollment_status VARCHAR(50) NOT NULL AFTER year_level;

