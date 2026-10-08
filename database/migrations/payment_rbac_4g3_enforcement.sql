-- CORE SMS2 DATABASE ONLY. Apply before deploying Batch 4G-3 application code.
-- Preconditions: preflight passed; (role_key,module_key) is unique; backup completed.
-- Existing explicit values for the new keys are preserved.
START TRANSACTION;
INSERT INTO role_permissions(role_key,module_key,granted)
SELECT x.role_key,x.module_key,
 CASE WHEN EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=x.role_key AND old.module_key IN ('payment.concern.view','payment.concern.review','payment.concern_review') AND old.granted=0) THEN 0 ELSE 1 END
FROM (
 SELECT 'accounting_officer' role_key,'payment.concern.evidence.review' module_key UNION ALL
 SELECT 'accounting_officer','payment.concern.decision' UNION ALL
 SELECT 'accounting_officer','payment.concern.verify'
) x
ON DUPLICATE KEY UPDATE module_key=VALUES(module_key);

INSERT INTO role_permissions(role_key,module_key,granted)
SELECT x.role_key,x.module_key,
 CASE WHEN EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=x.role_key AND old.module_key IN ('ledger.view','payment.ledger') AND old.granted=0) THEN 0 ELSE 1 END
FROM (SELECT 'accounting_officer' role_key,'ledger.export' module_key UNION ALL SELECT 'accounting_admin','ledger.export') x
ON DUPLICATE KEY UPDATE module_key=VALUES(module_key);

INSERT INTO role_permissions(role_key,module_key,granted) VALUES
 ('accounting_admin','fee.activate',1),('accounting_admin','report.export',1)
ON DUPLICATE KEY UPDATE module_key=VALUES(module_key);
COMMIT;