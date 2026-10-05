-- Read-only preflight for the Cashier academic-payment idempotency ledger.
-- Run this against the Payment database before the migration and retain the PASS row.
SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'payments' AND engine = 'InnoDB') = 1
         AND (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'payments'
                AND column_name IN ('payment_id', 'verified_by', 'transaction_type', 'payment_channel', 'payment_status')) = 5
         AND (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency') = 0
        THEN 'PASS'
        ELSE 'FAIL'
    END AS preflight_status,
    (SELECT engine FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'payments' LIMIT 1) AS payments_engine,
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency') AS existing_ledger_tables;
