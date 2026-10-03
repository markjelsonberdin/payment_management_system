-- Phase 5 rollback. Do not execute if any run assignment exists: snapshots are audit records.
SELECT CASE WHEN COUNT(*) = 0 THEN 'SAFE_TO_ROLL_BACK_SCHEMA_ONLY'
            ELSE 'BLOCKED_ASSIGNMENTS_EXIST'
       END AS rollback_guard
FROM billing_run_assignments;

-- Execute the ALTER only after the guard above returns SAFE_TO_ROLL_BACK_SCHEMA_ONLY.
-- ALTER TABLE billing_run_assignments
--   DROP COLUMN enrollment_status,
--   DROP COLUMN year_level,
--   DROP COLUMN program_code,
--   DROP COLUMN student_full_name,
--   DROP COLUMN student_number;
