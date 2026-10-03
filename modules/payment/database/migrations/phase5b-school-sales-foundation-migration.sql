-- Phase 5B School Sales variants, prices, applicability, and audit outbox.
-- Run only after the production preflight reports PASS and an item-type state
-- of FRESH or EXACT_SEEDED. Target: hf_db_bfim0j6t, MariaDB 11.8.8.
-- No USE statement: connect directly to the target database.
-- This file creates no item, variant, price, or applicability business data.

DROP PROCEDURE IF EXISTS phase5b_assert_foundation_safe;
DELIMITER //
CREATE PROCEDURE phase5b_assert_foundation_safe()
BEGIN
    DECLARE phase5_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE category_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE item_type_rows BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_type_rows BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B blocked: wrong Payment database.';
    END IF;
    IF VERSION() NOT LIKE '11.8.8-MariaDB%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B blocked: unverified MariaDB version.';
    END IF;

    SELECT COUNT(*) INTO phase5_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                         'school_sale_item_applicability', 'payment_audit_outbox');
    SELECT COUNT(*) INTO category_rows FROM school_sale_categories;
    SELECT COUNT(*) INTO item_rows FROM school_sale_items;
    SELECT COUNT(*) INTO item_type_rows FROM school_sale_item_types;
    SELECT COUNT(*) INTO exact_type_rows
    FROM school_sale_item_types
    WHERE (type_code = 'UNIFORM' AND type_name = 'Uniform' AND metadata_profile = 'UNIFORM_VARIANT' AND status = 'Active' AND sort_order = 10)
       OR (type_code = 'BOOK' AND type_name = 'Book' AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active' AND sort_order = 20)
       OR (type_code = 'LEARNING_MATERIAL' AND type_name = 'Learning Material' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 30)
       OR (type_code = 'ACCESSORY' AND type_name = 'Accessory' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 40)
       OR (type_code = 'GRADUATION_ITEM' AND type_name = 'Graduation Item' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 50)
       OR (type_code = 'OTHER_MERCHANDISE' AND type_name = 'Other Merchandise' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 60);

    IF phase5_tables <> 0 OR category_rows <> 4 OR item_rows <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B blocked: unexpected catalog schema or data state.';
    END IF;
    IF NOT (item_type_rows = 0 OR (item_type_rows = 6 AND exact_type_rows = 6)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5B blocked: conflicting controlled item types.';
    END IF;
END//
DELIMITER ;

CALL phase5b_assert_foundation_safe();
DROP PROCEDURE phase5b_assert_foundation_safe;

CREATE TABLE school_sale_item_variants (
    sale_variant_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_item_id INT UNSIGNED NOT NULL,
    variant_code VARCHAR(60) NOT NULL,
    variant_name VARCHAR(120) NOT NULL,
    sku VARCHAR(80) NULL,
    size_label VARCHAR(60) NULL,
    variant_metadata LONGTEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('Draft', 'Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_variant_id),
    UNIQUE KEY uq_school_sale_variant_code (sale_item_id, variant_code),
    UNIQUE KEY uq_school_sale_variant_sku (sku),
    KEY idx_school_sale_variant_item_status_sort (sale_item_id, status, sort_order),
    CONSTRAINT fk_school_sale_variant_item
        FOREIGN KEY (sale_item_id) REFERENCES school_sale_items (sale_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_school_sale_variant_code_nonblank CHECK (CHAR_LENGTH(TRIM(variant_code)) > 0),
    CONSTRAINT chk_school_sale_variant_name_nonblank CHECK (CHAR_LENGTH(TRIM(variant_name)) > 0),
    CONSTRAINT chk_school_sale_variant_metadata_json CHECK (variant_metadata IS NULL OR JSON_VALID(variant_metadata))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE school_sale_variant_prices (
    sale_variant_price_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_variant_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    effective_from DATETIME NOT NULL,
    effective_to DATETIME NULL,
    status ENUM('Draft', 'Active', 'Retired', 'Cancelled') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    activated_by INT UNSIGNED NULL,
    retired_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    activated_at DATETIME NULL,
    retired_at DATETIME NULL,
    PRIMARY KEY (sale_variant_price_id),
    UNIQUE KEY uq_school_sale_variant_price_start (sale_variant_id, effective_from),
    KEY idx_school_sale_variant_price_current (sale_variant_id, status, effective_from, effective_to),
    CONSTRAINT fk_school_sale_variant_price_variant
        FOREIGN KEY (sale_variant_id) REFERENCES school_sale_item_variants (sale_variant_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_school_sale_variant_price_amount CHECK (amount > 0),
    CONSTRAINT chk_school_sale_variant_price_currency CHECK (CHAR_LENGTH(TRIM(currency)) = 3),
    CONSTRAINT chk_school_sale_variant_price_window CHECK (effective_to IS NULL OR effective_to > effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE school_sale_item_applicability (
    sale_item_applicability_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_item_id INT UNSIGNED NOT NULL,
    program_code VARCHAR(60) NULL,
    year_level VARCHAR(20) NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    deactivated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deactivated_at DATETIME NULL,
    active_program_scope VARCHAR(60) GENERATED ALWAYS AS (
        CASE WHEN status = 'Active'
             THEN COALESCE(NULLIF(TRIM(program_code), ''), '__ANY__')
             ELSE NULL END
    ) PERSISTENT,
    active_year_scope VARCHAR(20) GENERATED ALWAYS AS (
        CASE WHEN status = 'Active'
             THEN COALESCE(NULLIF(TRIM(year_level), ''), '__ANY__')
             ELSE NULL END
    ) PERSISTENT,
    PRIMARY KEY (sale_item_applicability_id),
    UNIQUE KEY uq_school_sale_item_active_scope (sale_item_id, active_program_scope, active_year_scope),
    KEY idx_school_sale_applicability_item_status (sale_item_id, status),
    KEY idx_school_sale_applicability_student_scope (program_code, year_level, status),
    CONSTRAINT fk_school_sale_applicability_item
        FOREIGN KEY (sale_item_id) REFERENCES school_sale_items (sale_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_school_sale_applicability_scope CHECK (
        NULLIF(TRIM(program_code), '') IS NOT NULL OR NULLIF(TRIM(year_level), '') IS NOT NULL
    ),
    CONSTRAINT chk_school_sale_applicability_deactivation CHECK (
        (status = 'Active' AND deactivated_at IS NULL AND deactivated_by IS NULL)
        OR status = 'Inactive'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE payment_audit_outbox (
    audit_outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correlation_id CHAR(36) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    action VARCHAR(40) NOT NULL,
    module_key VARCHAR(60) NOT NULL DEFAULT 'payment',
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    detail VARCHAR(500) NOT NULL,
    before_state LONGTEXT NULL,
    after_state LONGTEXT NULL,
    committed_result LONGTEXT NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_user_name VARCHAR(150) NULL,
    actor_role_key VARCHAR(40) NULL,
    actor_ip_address VARCHAR(45) NULL,
    actor_user_agent VARCHAR(255) NULL,
    delivery_status ENUM('Pending', 'Processing', 'Delivered', 'Failed') NOT NULL DEFAULT 'Pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_attempt_at DATETIME NULL,
    delivered_at DATETIME NULL,
    core_activity_log_id BIGINT UNSIGNED NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_outbox_id),
    UNIQUE KEY uq_payment_audit_outbox_correlation (correlation_id),
    KEY idx_payment_audit_outbox_fingerprint (request_fingerprint),
    KEY idx_payment_audit_outbox_delivery (delivery_status, next_attempt_at),
    KEY idx_payment_audit_outbox_entity (entity_type, entity_id, created_at),
    CONSTRAINT chk_payment_audit_outbox_correlation CHECK (CHAR_LENGTH(TRIM(correlation_id)) = 36),
    CONSTRAINT chk_payment_audit_outbox_fingerprint CHECK (CHAR_LENGTH(request_fingerprint) = 64),
    CONSTRAINT chk_payment_audit_outbox_before_json CHECK (before_state IS NULL OR JSON_VALID(before_state)),
    CONSTRAINT chk_payment_audit_outbox_after_json CHECK (after_state IS NULL OR JSON_VALID(after_state)),
    CONSTRAINT chk_payment_audit_outbox_result_json CHECK (JSON_VALID(committed_result)),
    CONSTRAINT chk_payment_audit_outbox_delivery_state CHECK (
        (delivery_status = 'Delivered' AND delivered_at IS NOT NULL AND core_activity_log_id IS NOT NULL)
        OR delivery_status <> 'Delivered'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Effective-price overlap and complete item activation prerequisites require
-- transactional service checks. SQL validation can report current violations,
-- but this migration intentionally creates no triggers and enables no Cashier flow.

