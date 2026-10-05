-- Run immediately after cashier-payment-idempotency-migration.sql.
SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency' AND engine = 'InnoDB') = 1
         AND (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency'
                AND column_name IN ('cashier_user_id', 'idempotency_key', 'request_fingerprint', 'payment_id')) = 4
         AND (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency'
                AND index_name = 'uq_cashier_payment_idempotency_request' AND non_unique = 0) = 1
         AND (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'cashier_payment_idempotency'
                AND index_name = 'uq_cashier_payment_idempotency_payment' AND non_unique = 0) = 1
        THEN 'PASS'
        ELSE 'FAIL'
    END AS validation_status,
    (SELECT COUNT(*) FROM cashier_payment_idempotency) AS ledger_rows;
