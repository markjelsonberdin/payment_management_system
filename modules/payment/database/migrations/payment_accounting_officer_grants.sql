-- CORE SMS2 DATABASE ONLY. Review live grants and back up before applying.
-- The accounting_officer role already exists on the reported live database.
-- Do not run this against payment_db. Do not revoke cashier grants until an
-- Accounting Officer account and all guarded routes have been tested.
INSERT INTO role_permissions (role_key, module_key, granted)
VALUES
 ('accounting_officer', 'payment', 1),
 ('accounting_officer', 'payment.billing', 1),
 ('accounting_officer', 'payment.discount', 1),
 ('accounting_officer', 'payment.ledger', 1),
 ('accounting_officer', 'payment.analytics', 1),
 ('accounting_officer', 'payment.concern_review', 1),
 ('finance', 'payment.user_management', 1),
 ('cashier', 'payment.walkin_history', 1)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

-- Separate post-verification cutover (run only after Accounting works):
-- UPDATE role_permissions SET granted = 0
-- WHERE role_key = 'cashier'
--   AND module_key IN ('payment.billing', 'payment.discount', 'payment.ledger',
--                      'payment.analytics', 'payment.concern_review');
