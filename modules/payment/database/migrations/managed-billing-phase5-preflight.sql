-- Phase 5 preflight. Run only against the intended database before the Phase 5 migration.
-- The deployment wrapper must hard-abort unless DATABASE() ends with '_test' for test validation.
SELECT CASE
  WHEN DATABASE() LIKE '%\\_test' ESCAPE '\\' THEN 'TEST_TARGET_CONFIRMED'
  ELSE 'BLOCKED_NOT_A_TEST_DATABASE'
END AS test_target_guard;

SELECT CASE
  WHEN COUNT(*) = 1 THEN 'PASS'
  ELSE 'BLOCKED_PHASE2_ASSIGNMENT_TABLE_MISSING'
END AS assignment_table_check
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'billing_run_assignments';

SELECT CASE
  WHEN COUNT(*) = 0 THEN 'READY'
  ELSE 'BLOCKED_PHASE5_COLUMNS_ALREADY_PRESENT_OR_PARTIAL'
END AS phase5_column_check,
       GROUP_CONCAT(column_name ORDER BY ordinal_position) AS existing_phase5_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'billing_run_assignments'
  AND column_name IN ('student_number','student_full_name','program_code','year_level','enrollment_status');

SELECT COUNT(*) AS existing_assignment_rows
FROM billing_run_assignments;
-- Any non-zero row count requires an explicit snapshot/backfill decision. Do not apply this
-- first-time migration to populated assignments without that separate approved procedure.
