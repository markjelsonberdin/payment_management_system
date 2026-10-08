-- Run only before any administrator edits these 4G-3 permissions.
-- This removes only newly introduced keys; it cannot restore post-deployment edits.
START TRANSACTION;
DELETE FROM role_permissions WHERE (role_key='accounting_officer' AND module_key IN ('payment.concern.evidence.review','payment.concern.decision','payment.concern.verify','ledger.export')) OR (role_key='accounting_admin' AND module_key IN ('ledger.export','fee.activate','report.export'));
COMMIT;