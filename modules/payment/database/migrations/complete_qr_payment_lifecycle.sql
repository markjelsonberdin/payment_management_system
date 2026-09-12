-- Canonical QR Ph lifecycle fields used by create, polling, cancellation, and webhooks.
-- Run this once against payment_db before deploying the matching PHP changes.

ALTER TABLE payments
    MODIFY COLUMN payment_status
        ENUM('Pending','Verified','Rejected','Failed','Cancelled','Expired')
        NOT NULL DEFAULT 'Pending',
    ADD COLUMN expires_at DATETIME NULL AFTER verified_at,
    ADD COLUMN gateway_environment ENUM('test','live') NULL AFTER payment_channel,
    ADD COLUMN payment_method_id VARCHAR(255) NULL AFTER payment_intent_id;

ALTER TABLE payments
    ADD INDEX idx_qr_pending_expiry
        (student_id, billing_id, payment_channel, payment_status, expires_at),
    ADD INDEX idx_payment_environment
        (gateway_environment, payment_status);
