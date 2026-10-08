-- Batch 4G-3 Core RBAC preflight. READ ONLY.
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='role_permissions' ORDER BY ORDINAL_POSITION;
SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) indexed_columns FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='role_permissions' GROUP BY INDEX_NAME,NON_UNIQUE;
SELECT role_key,module_key,COUNT(*) duplicate_count FROM role_permissions GROUP BY role_key,module_key HAVING COUNT(*)>1;
SELECT role_key,module_key,granted FROM role_permissions WHERE granted IS NULL OR granted NOT IN (0,1);

-- Canonical/legacy collisions. Any revocation wins at runtime after 4G-3.
SELECT rp.role_key,m.canonical_key,COUNT(*) rows_found,MIN(rp.granted) fail_safe_grant,GROUP_CONCAT(CONCAT(rp.module_key,'=',rp.granted) ORDER BY rp.module_key) stored_rows
FROM role_permissions rp
JOIN (
 SELECT 'ledger.view' canonical_key,'ledger.view' stored_key UNION ALL SELECT 'ledger.view','payment.ledger'
 UNION ALL SELECT 'report.view','report.view' UNION ALL SELECT 'report.view','payment.analytics' UNION ALL SELECT 'report.view','payment.collection_analytics_view'
 UNION ALL SELECT 'payment.concern.view','payment.concern.view' UNION ALL SELECT 'payment.concern.view','payment.concern.review' UNION ALL SELECT 'payment.concern.view','payment.concern_review'
) m ON m.stored_key=rp.module_key
WHERE rp.role_key IN ('mis_admin','accounting_admin','accounting_officer','cashier')
GROUP BY rp.role_key,m.canonical_key HAVING COUNT(*)>1;

SELECT role_key,module_key,granted FROM role_permissions
WHERE module_key IN ('payment.concern.evidence.review','payment.concern.decision','payment.concern.verify','ledger.export','fee.activate','report.export')
ORDER BY role_key,module_key;
SELECT role_key,module_key,granted FROM role_permissions WHERE role_key='mis_admin' AND module_key IN ('payment.mis_overview','payment.permissions.manage');