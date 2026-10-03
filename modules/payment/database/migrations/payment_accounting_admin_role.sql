-- SMS2 authentication database only. Additive and idempotent.
INSERT INTO roles (role_key, label, description, is_system)
VALUES ('accounting_admin', 'Accounting Admin', 'Financial authority: fee management, bulk approval, financial review, AR oversight, reports, and payment-verification oversight', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), is_system = VALUES(is_system);

INSERT INTO role_permissions (role_key, module_key, granted)
VALUES ('accounting_admin', 'payment', 1)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
