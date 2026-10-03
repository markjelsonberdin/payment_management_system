-- READ-ONLY preflight. Run against the target immediately before future DDL.

SELECT DATABASE() AS target_database, VERSION() AS database_version;

-- Must return PASS and NULL detected_objects before a first application.
SELECT CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'BLOCKED — POSSIBLE PARTIAL MIGRATION' END AS result,
       GROUP_CONCAT(CONCAT(object_type, ':', object_name) ORDER BY object_type, object_name) AS detected_objects
FROM (
  SELECT 'TABLE' object_type, table_name object_name FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name IN ('managed_assessment_headers','billing_runs','billing_run_fee_versions','billing_run_assignments','billing_notification_outbox')
  UNION ALL SELECT 'COLUMN', CONCAT(table_name, '.', column_name) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'billing_items' AND column_name = 'fee_version_id'
  UNION ALL SELECT 'INDEX', CONCAT(table_name, '.', index_name) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND ((table_name = 'billing_items' AND index_name IN ('idx_billing_items_fee_version','uq_billing_items_billing_fee_version')) OR (table_name = 'billing_notification_outbox' AND index_name IN ('uq_billing_notification_outbox_event','uq_billing_notification_outbox_run_student','idx_billing_notification_outbox_delivery','idx_billing_notification_outbox_run_status','idx_billing_notification_outbox_student')))
  UNION ALL SELECT 'FK', CONCAT(table_name, '.', constraint_name) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND constraint_name IN ('fk_billing_items_fee_version','fk_managed_assessment_header_student','fk_managed_assessment_header_billing','fk_billing_run_fee_versions_run','fk_billing_run_fee_versions_fee_version','fk_billing_run_assignments_run','fk_billing_run_assignments_student','fk_billing_run_assignments_billing','fk_billing_notification_outbox_run','fk_billing_notification_outbox_student','fk_billing_notification_outbox_billing')
) proposed_objects;

SELECT table_name, engine, table_collation FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN ('billing','billing_items','fee_versions','fees','fee_applicability','students','payment_allocations','payment_notifications') ORDER BY table_name;
SELECT table_name, column_name, column_type, is_nullable, column_default, extra, collation_name
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('billing','billing_items','fee_versions','fees','fee_applicability','students','payment_allocations','payment_notifications') ORDER BY table_name, ordinal_position;
SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ('billing_items','fee_versions','billing','payment_notifications') ORDER BY table_name,index_name,seq_in_index;
SELECT table_name,constraint_name,column_name,referenced_table_name,referenced_column_name FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name IN ('billing_items','fee_versions','billing','payment_allocations') ORDER BY table_name,constraint_name,ordinal_position;
SELECT trigger_name,event_object_table,action_timing,event_manipulation,action_statement FROM information_schema.triggers
WHERE trigger_schema = DATABASE() AND event_object_table IN ('billing','billing_items','payment_allocations') ORDER BY event_object_table,trigger_name;

SELECT COUNT(*) AS orphaned_billing_id_count FROM billing_items bi LEFT JOIN billing b ON b.billing_id=bi.billing_id WHERE b.billing_id IS NULL;
SELECT COUNT(*) AS orphaned_fee_id_count FROM billing_items bi LEFT JOIN fees f ON f.fee_id=bi.fee_id WHERE f.fee_id IS NULL;
SELECT billing_id,fee_id,COUNT(*) duplicate_count FROM billing_items GROUP BY billing_id,fee_id HAVING COUNT(*)>1 ORDER BY duplicate_count DESC,billing_id,fee_id;
SELECT student_id,academic_year,semester,COUNT(*) billing_row_count,GROUP_CONCAT(DISTINCT billing_type ORDER BY billing_type) billing_types,GROUP_CONCAT(DISTINCT billing_status ORDER BY billing_status) billing_statuses FROM billing GROUP BY student_id,academic_year,semester HAVING COUNT(*)>1 ORDER BY billing_row_count DESC,student_id LIMIT 50;
SELECT student_id,academic_year,semester,SUM(billing_type='Enrollment') enrollment_count,SUM(billing_type='Assessment') assessment_count,SUM(billing_type='Adjustment') adjustment_count FROM billing GROUP BY student_id,academic_year,semester HAVING (enrollment_count>0 AND assessment_count>0) OR (assessment_count>0 AND adjustment_count>0) ORDER BY student_id LIMIT 50;
SELECT COUNT(*) orphaned_fee_version_count FROM fee_versions fv LEFT JOIN fees f ON f.fee_id=fv.fee_id WHERE f.fee_id IS NULL;
SELECT fee_version_id,fee_id,academic_year,semester,amount FROM fee_versions WHERE effective_status='Active' AND amount<=0;
SELECT fee_id,academic_year,semester,COUNT(*) scope_count FROM fee_versions GROUP BY fee_id,academic_year,semester HAVING COUNT(*)>1;
SELECT effective_status,behavior,is_required,COUNT(*) row_count FROM fee_versions GROUP BY effective_status,behavior,is_required ORDER BY effective_status,behavior,is_required;
SELECT COUNT(*) versions_without_applicability FROM fee_versions fv LEFT JOIN fee_applicability fa ON fa.fee_version_id=fv.fee_version_id WHERE fa.fee_version_id IS NULL;
SELECT COUNT(*) orphaned_applicability_count FROM fee_applicability fa LEFT JOIN fee_versions fv ON fv.fee_version_id=fa.fee_version_id WHERE fv.fee_version_id IS NULL;
SELECT fee_version_id,normalized_course_scope,normalized_year_scope,COUNT(*) duplicate_count FROM fee_applicability GROUP BY fee_version_id,normalized_course_scope,normalized_year_scope HAVING COUNT(*)>1;
SELECT DISTINCT course,year_level FROM students ORDER BY course,year_level;
SELECT COUNT(*) orphaned_allocation_billing_item_count FROM payment_allocations pa LEFT JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id WHERE bi.billing_item_id IS NULL;
SELECT column_name,column_type,character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_notifications' AND column_name IN ('recipient_user_id','event_key','title','body','target_url') ORDER BY ordinal_position;
