-- CORE SMS2 DATABASE ONLY. Batch 4B permission-data rollback.
-- Back up the Core database before execution.

DELETE FROM role_permissions
WHERE (role_key = 'accounting_officer' AND module_key IN (
    'payment.reconciliation.view',
    'payment.reconciliation.process',
    'payment.reconciliation.import'
  ))
   OR (role_key = 'accounting_admin' AND module_key IN (
    'payment.reconciliation.view',
    'payment.reconciliation.exception.approve'
  ));
