-- CORE SMS2 DATABASE ONLY. Do not run this against payment_db.
-- Additive and idempotent RBAC data migration for Batch 4B.
-- Back up the Core database and review current grants before execution.

INSERT INTO role_permissions (role_key, module_key, granted)
VALUES
 ('accounting_officer', 'payment.reconciliation.view', 1),
 ('accounting_officer', 'payment.reconciliation.process', 1),
 ('accounting_officer', 'payment.reconciliation.import', 1),
 ('accounting_admin', 'payment.reconciliation.view', 1),
 ('accounting_admin', 'payment.reconciliation.exception.approve', 1)
-- Preserve any explicit revocation already recorded for an existing row.
ON DUPLICATE KEY UPDATE module_key = VALUES(module_key);

-- Validation (read only): expect exactly the five rows above, all granted = 1.
SELECT role_key, module_key, granted
FROM role_permissions
WHERE module_key IN (
  'payment.reconciliation.view',
  'payment.reconciliation.process',
  'payment.reconciliation.import',
  'payment.reconciliation.exception.approve'
)
ORDER BY role_key, module_key;
