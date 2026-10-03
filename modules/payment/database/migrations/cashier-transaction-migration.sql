-- C4 Cashier Transaction Upgrade migration.
-- Run only after a PASS result from cashier-transaction-preflight.sql, a verified backup,
-- and separate database-execution approval. This file is MariaDB 10.4-compatible.
-- Never run modules/payment/database/payment.sql as a migration.

DROP PROCEDURE IF EXISTS c4_assert_cashier_transaction_dependencies;
DELIMITER //
CREATE PROCEDURE c4_assert_cashier_transaction_dependencies()
BEGIN
    DECLARE catalog_tables INT DEFAULT 0;
    DECLARE payment_tables INT DEFAULT 0;
    DECLARE baseline_tables INT DEFAULT 0;
    DECLARE c4_tables INT DEFAULT 0;

    SELECT COUNT(*) INTO catalog_tables FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('school_sale_categories', 'school_sale_item_types', 'school_sale_items', 'school_sale_item_variants', 'school_sale_variant_prices', 'school_sale_item_applicability', 'school_sale_book_details');
    SELECT COUNT(*) INTO payment_tables FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('students', 'payments', 'payment_allocations', 'billing', 'billing_items', 'payment_audit_outbox');
    SELECT COUNT(*) INTO baseline_tables FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('official_receipt_sequences', 'cash_sales', 'cash_sale_items', 'cashier_receipt_print_events');
    SELECT COUNT(*) INTO c4_tables FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('cashier_transactions', 'cashier_transaction_fee_payments', 'cashier_sale_voids', 'cash_sale_line_reversals');

    IF catalog_tables <> 7 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C4 blocked: Accounting Admin catalog foundation is incomplete.'; END IF;
    IF payment_tables <> 6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C4 blocked: Payment financial or audit dependency is missing.'; END IF;
    IF baseline_tables NOT IN (0, 4) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C4 blocked: Cashier baseline is partially deployed.'; END IF;
    IF c4_tables <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C4 blocked: Cashier transaction schema already exists or is partial.'; END IF;
END//
DELIMITER ;

CALL c4_assert_cashier_transaction_dependencies();
DROP PROCEDURE c4_assert_cashier_transaction_dependencies;

-- Cashier-owned baseline extracted from payment.sql. No catalog table is created or altered.
CREATE TABLE IF NOT EXISTS official_receipt_sequences (
    sequence_key VARCHAR(32) NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sequence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_sales (
    cash_sale_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    cashier_id INT UNSIGNED NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    academic_year_snapshot VARCHAR(20) NULL,
    year_level_snapshot VARCHAR(50) NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    cash_received DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sale_status ENUM('Completed','Voided') NOT NULL DEFAULT 'Completed',
    remarks TEXT NULL,
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cash_sale_id),
    UNIQUE KEY uq_cash_sale_receipt (receipt_number),
    KEY idx_cash_sale_cashier_date (cashier_id, sold_at),
    KEY idx_cash_sale_student_date (student_id, sold_at),
    CONSTRAINT fk_cash_sale_student FOREIGN KEY (student_id) REFERENCES students (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_sale_items (
    cash_sale_line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cash_sale_id BIGINT UNSIGNED NOT NULL,
    sale_item_id INT UNSIGNED NULL,
    sale_category_id INT UNSIGNED NULL,
    category_name_snapshot VARCHAR(100) NOT NULL,
    item_name_snapshot VARCHAR(150) NOT NULL,
    unit_price_snapshot DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (cash_sale_line_id),
    KEY idx_cash_sale_line_sale (cash_sale_id),
    CONSTRAINT fk_cash_sale_line_sale FOREIGN KEY (cash_sale_id) REFERENCES cash_sales (cash_sale_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cashier_receipt_print_events (
    receipt_print_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_type ENUM('payment','cash_sale') NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    rendered_by INT UNSIGNED NOT NULL,
    rendered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (receipt_print_event_id),
    KEY idx_receipt_print_record (record_type, record_id),
    KEY idx_receipt_print_user (rendered_by, rendered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cashier_transactions (
    cashier_transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_number VARCHAR(50) NOT NULL,
    transaction_type ENUM('FEE_PAYMENT','SCHOOL_SALE','MIXED') NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    cashier_user_id INT UNSIGNED NOT NULL,
    academic_year_snapshot VARCHAR(20) NULL,
    semester_snapshot ENUM('1st','2nd','Summer') NULL,
    fee_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    school_sale_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(10,2) NOT NULL,
    cash_received DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    official_receipt_number VARCHAR(50) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    status ENUM('Completed') NOT NULL DEFAULT 'Completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cashier_transaction_id),
    UNIQUE KEY uq_cashier_transaction_number (transaction_number),
    UNIQUE KEY uq_cashier_transaction_receipt (official_receipt_number),
    UNIQUE KEY uq_cashier_transaction_idempotency (cashier_user_id, idempotency_key),
    KEY idx_cashier_transaction_correlation (correlation_id),
    KEY idx_cashier_transaction_student_date (student_id, completed_at),
    KEY idx_cashier_transaction_cashier_date (cashier_user_id, completed_at),
    CONSTRAINT fk_cashier_transaction_student FOREIGN KEY (student_id) REFERENCES students (student_id),
    CONSTRAINT chk_cashier_transaction_totals CHECK (fee_total >= 0 AND school_sale_total >= 0 AND grand_total = fee_total + school_sale_total AND cash_received >= grand_total AND change_amount = cash_received - grand_total),
    CONSTRAINT chk_cashier_transaction_type_totals CHECK ((transaction_type = 'FEE_PAYMENT' AND fee_total > 0 AND school_sale_total = 0) OR (transaction_type = 'SCHOOL_SALE' AND fee_total = 0 AND school_sale_total > 0) OR (transaction_type = 'MIXED' AND fee_total > 0 AND school_sale_total > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cashier_transaction_fee_payments (
    cashier_transaction_fee_payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cashier_transaction_id BIGINT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cashier_transaction_fee_payment_id),
    UNIQUE KEY uq_cashier_transaction_fee_payment_transaction (cashier_transaction_id),
    UNIQUE KEY uq_cashier_transaction_fee_payment_payment (payment_id),
    CONSTRAINT fk_cashier_transaction_fee_payment_header FOREIGN KEY (cashier_transaction_id) REFERENCES cashier_transactions (cashier_transaction_id),
    CONSTRAINT fk_cashier_transaction_fee_payment_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cash_sales
    ADD COLUMN cashier_transaction_id BIGINT UNSIGNED NULL AFTER cash_sale_id,
    ADD UNIQUE KEY uq_cash_sales_cashier_transaction (cashier_transaction_id),
    ADD CONSTRAINT fk_cash_sale_cashier_transaction FOREIGN KEY (cashier_transaction_id) REFERENCES cashier_transactions (cashier_transaction_id);

ALTER TABLE cash_sale_items
    ADD COLUMN sale_variant_id INT UNSIGNED NULL AFTER sale_item_id,
    ADD COLUMN sale_variant_price_id BIGINT UNSIGNED NULL AFTER sale_variant_id,
    ADD COLUMN item_code_snapshot VARCHAR(60) NULL AFTER item_name_snapshot,
    ADD COLUMN category_code_snapshot VARCHAR(60) NULL AFTER category_name_snapshot,
    ADD COLUMN item_type_code_snapshot VARCHAR(60) NULL AFTER category_code_snapshot,
    ADD COLUMN item_type_name_snapshot VARCHAR(120) NULL AFTER item_type_code_snapshot,
    ADD COLUMN variant_code_snapshot VARCHAR(60) NULL AFTER item_type_name_snapshot,
    ADD COLUMN variant_name_snapshot VARCHAR(120) NULL AFTER variant_code_snapshot,
    ADD COLUMN size_label_snapshot VARCHAR(60) NULL AFTER variant_name_snapshot,
    ADD COLUMN price_effective_from_snapshot DATETIME NULL AFTER unit_price_snapshot,
    ADD COLUMN price_effective_to_snapshot DATETIME NULL AFTER price_effective_from_snapshot,
    ADD COLUMN applicability_mode_snapshot ENUM('ALL','RESTRICTED') NULL AFTER price_effective_to_snapshot,
    ADD COLUMN student_program_snapshot VARCHAR(60) NULL AFTER applicability_mode_snapshot,
    ADD COLUMN student_year_level_snapshot VARCHAR(20) NULL AFTER student_program_snapshot,
    ADD COLUMN applicability_result_snapshot ENUM('MATCHED','NOT_APPLICABLE') NULL AFTER student_year_level_snapshot,
    ADD COLUMN book_title_snapshot VARCHAR(255) NULL AFTER applicability_result_snapshot,
    ADD COLUMN book_author_snapshot VARCHAR(255) NULL AFTER book_title_snapshot,
    ADD COLUMN book_publisher_snapshot VARCHAR(255) NULL AFTER book_author_snapshot,
    ADD COLUMN book_edition_snapshot VARCHAR(100) NULL AFTER book_publisher_snapshot,
    ADD COLUMN book_isbn_snapshot VARCHAR(32) NULL AFTER book_edition_snapshot,
    ADD KEY idx_cash_sale_line_variant (sale_variant_id),
    ADD KEY idx_cash_sale_line_price (sale_variant_price_id),
    ADD CONSTRAINT fk_cash_sale_line_variant FOREIGN KEY (sale_variant_id) REFERENCES school_sale_item_variants (sale_variant_id),
    ADD CONSTRAINT fk_cash_sale_line_price FOREIGN KEY (sale_variant_price_id) REFERENCES school_sale_variant_prices (sale_variant_price_id);

CREATE TABLE cashier_sale_voids (
    cashier_sale_void_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_cashier_transaction_id BIGINT UNSIGNED NOT NULL,
    original_cash_sale_id BIGINT UNSIGNED NOT NULL,
    cashier_user_id INT UNSIGNED NOT NULL,
    refund_slip_number VARCHAR(50) NOT NULL,
    refund_total DECIMAL(10,2) NOT NULL,
    cash_refunded DECIMAL(10,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    status ENUM('Completed') NOT NULL DEFAULT 'Completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cashier_sale_void_id),
    UNIQUE KEY uq_cashier_sale_void_refund_slip (refund_slip_number),
    UNIQUE KEY uq_cashier_sale_void_idempotency (cashier_user_id, idempotency_key),
    KEY idx_cashier_sale_void_correlation (correlation_id),
    KEY idx_cashier_sale_void_original_transaction (original_cashier_transaction_id),
    KEY idx_cashier_sale_void_original_sale (original_cash_sale_id),
    CONSTRAINT fk_cashier_sale_void_transaction FOREIGN KEY (original_cashier_transaction_id) REFERENCES cashier_transactions (cashier_transaction_id),
    CONSTRAINT fk_cashier_sale_void_sale FOREIGN KEY (original_cash_sale_id) REFERENCES cash_sales (cash_sale_id),
    CONSTRAINT chk_cashier_sale_void_refund CHECK (refund_total > 0 AND cash_refunded = refund_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cash_sale_line_reversals (
    cash_sale_line_reversal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cashier_sale_void_id BIGINT UNSIGNED NOT NULL,
    original_cash_sale_line_id BIGINT UNSIGNED NOT NULL,
    original_cash_sale_id BIGINT UNSIGNED NOT NULL,
    quantity_snapshot INT UNSIGNED NOT NULL,
    unit_price_snapshot DECIMAL(10,2) NOT NULL,
    refunded_line_total DECIMAL(10,2) NOT NULL,
    item_name_snapshot VARCHAR(150) NOT NULL,
    variant_name_snapshot VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cash_sale_line_reversal_id),
    UNIQUE KEY uq_cash_sale_line_reversal_original_line (original_cash_sale_line_id),
    KEY idx_cash_sale_line_reversal_void (cashier_sale_void_id),
    KEY idx_cash_sale_line_reversal_sale (original_cash_sale_id),
    CONSTRAINT fk_cash_sale_line_reversal_void FOREIGN KEY (cashier_sale_void_id) REFERENCES cashier_sale_voids (cashier_sale_void_id),
    CONSTRAINT fk_cash_sale_line_reversal_line FOREIGN KEY (original_cash_sale_line_id) REFERENCES cash_sale_items (cash_sale_line_id),
    CONSTRAINT fk_cash_sale_line_reversal_sale FOREIGN KEY (original_cash_sale_id) REFERENCES cash_sales (cash_sale_id),
    CONSTRAINT chk_cash_sale_line_reversal_amount CHECK (quantity_snapshot > 0 AND unit_price_snapshot > 0 AND refunded_line_total = quantity_snapshot * unit_price_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cashier_receipt_print_events
    MODIFY COLUMN record_type ENUM('payment','cash_sale','cashier_transaction','cash_sale_void') NOT NULL,
    ADD COLUMN cashier_transaction_id BIGINT UNSIGNED NULL AFTER record_id,
    ADD COLUMN cashier_sale_void_id BIGINT UNSIGNED NULL AFTER cashier_transaction_id,
    ADD KEY idx_receipt_print_transaction (cashier_transaction_id),
    ADD KEY idx_receipt_print_void (cashier_sale_void_id),
    ADD CONSTRAINT fk_receipt_print_transaction FOREIGN KEY (cashier_transaction_id) REFERENCES cashier_transactions (cashier_transaction_id),
    ADD CONSTRAINT fk_receipt_print_void FOREIGN KEY (cashier_sale_void_id) REFERENCES cashier_sale_voids (cashier_sale_void_id);
