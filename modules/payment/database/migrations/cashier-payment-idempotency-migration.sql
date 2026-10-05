-- Additive Cashier-only idempotency ledger for academic walk-in payments.
-- Run only after cashier-payment-idempotency-preflight.sql returns PASS and a
-- restorable Payment database backup has been confirmed. This is not auto-run.
-- The ledger is separate from payments/billing and does not change their schema.
CREATE TABLE cashier_payment_idempotency (
    cashier_payment_idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cashier_user_id INT UNSIGNED NOT NULL,
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cashier_payment_idempotency_id),
    UNIQUE KEY uq_cashier_payment_idempotency_request (cashier_user_id, idempotency_key),
    UNIQUE KEY uq_cashier_payment_idempotency_payment (payment_id),
    KEY idx_cashier_payment_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
