-- PRE-USE RECOVERY ONLY. Do not run after managed financial data exists.
-- MariaDB DDL is ordered, not guaranteed atomic. Drop only objects confirmed to exist.

SELECT COUNT(*) AS managed_assessment_headers_count FROM managed_assessment_headers;
SELECT COUNT(*) AS billing_runs_count FROM billing_runs;
SELECT COUNT(*) AS billing_run_fee_versions_count FROM billing_run_fee_versions;
SELECT COUNT(*) AS billing_run_assignments_count FROM billing_run_assignments;
SELECT COUNT(*) AS billing_notification_outbox_count FROM billing_notification_outbox;
SELECT COUNT(*) AS managed_billing_item_count
FROM billing_items WHERE fee_version_id IS NOT NULL;

-- Every result above must be zero before any recovery DDL is considered safe.
-- Step 6 recovery: DROP TABLE billing_notification_outbox;
-- Step 5 recovery: DROP TABLE billing_run_assignments;
-- Step 4 recovery: DROP TABLE billing_run_fee_versions;
-- Step 3 recovery: DROP TABLE billing_runs;
-- Step 2 recovery: DROP TABLE managed_assessment_headers;
-- Step 1 recovery:
-- ALTER TABLE billing_items
--   DROP FOREIGN KEY fk_billing_items_fee_version,
--   DROP INDEX uq_billing_items_billing_fee_version,
--   DROP INDEX idx_billing_items_fee_version,
--   DROP COLUMN fee_version_id;

-- Once managed records exist, rollback requires a separately approved preservation migration.
