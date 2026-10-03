-- Stage 1: SMS2 authentication database only.  No financial tables are read
-- or changed.  Take a backup and run this before the retirement preflight.
START TRANSACTION;

INSERT INTO roles (role_key, label, description, is_system)
VALUES ('mis_admin', 'Payment MIS Admin', 'Payment-scoped technical administration and personnel provisioning', 1)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), is_system=VALUES(is_system);

INSERT INTO roles (role_key, label, description, is_system)
VALUES
 ('accounting_admin', 'Accounting Admin', 'Financial authority: fee management, bulk approval, financial review, AR oversight, reports, and payment-verification oversight', 1),
 ('accounting_officer', 'Accounting Officer', 'Financial operations: individual billing, managed bulk creation and processing, payment verification, concerns, ledger, and AR monitoring', 1),
 ('cashier', 'Cashier', 'Walk-in collection, receipts, and limited payment lookup only', 1)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), is_system=VALUES(is_system);

INSERT INTO role_permissions (role_key, module_key, granted)
VALUES ('mis_admin', 'payment', 1)
ON DUPLICATE KEY UPDATE granted=VALUES(granted);

-- Existing Finance users are intentionally converted to Payment-only MIS.
UPDATE users SET role_key='mis_admin', updated_at=NOW() WHERE role_key='finance';

-- Finance and Payment Admin retain no Payment-module grant after cutover.
UPDATE role_permissions SET granted=0
WHERE role_key IN ('finance','payment_admin')
  AND (module_key='payment' OR module_key LIKE 'payment.%');

COMMIT;

-- Stage 2 preflight (run separately, after all active references are clear):
-- SELECT role_key, COUNT(*) AS active_users FROM users
-- WHERE role_key IN ('finance','payment_admin') AND status='active' GROUP BY role_key;
-- SELECT role_key, module_key FROM role_permissions
-- WHERE role_key IN ('finance','payment_admin') AND granted=1;
-- Only when both queries return zero rows may obsolete role mappings be retired.
-- This migration deliberately does not delete historical roles or audit rows.
