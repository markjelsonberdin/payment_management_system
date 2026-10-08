-- Batch 4G-2 read-only production preflight. Do not modify production data.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions'
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions'
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

SELECT role_key, module_key, COUNT(*) AS duplicate_count
FROM role_permissions
GROUP BY role_key, module_key
HAVING COUNT(*) > 1;

SELECT role_key, module_key, granted
FROM role_permissions
WHERE granted NOT IN (0,1) OR granted IS NULL;

SELECT role_key, COUNT(*) AS permission_rows
FROM role_permissions
WHERE role_key IN ('mis_admin','accounting_admin','accounting_officer','cashier')
GROUP BY role_key
ORDER BY role_key;

SELECT role_key, module_key, granted
FROM role_permissions
WHERE role_key = 'mis_admin'
  AND module_key IN ('payment.mis_overview','payment.permissions.manage');