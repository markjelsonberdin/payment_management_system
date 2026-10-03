-- Phase 4B: additive shared-core audit extension.
-- Execute only after phase4b-catalog-foundation-preflight.sql reports PASS.
-- Existing logActivity() callers remain compatible because every added column is nullable.
USE `hf_db_w3krndst`;

ALTER TABLE activity_logs
    ADD COLUMN entity_type VARCHAR(60) NULL AFTER module_key,
    ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type,
    ADD COLUMN before_state LONGTEXT NULL AFTER detail,
    ADD COLUMN after_state LONGTEXT NULL AFTER before_state,
    ADD COLUMN correlation_id CHAR(36) NULL AFTER after_state,
    ADD KEY idx_logs_entity (entity_type, entity_id, created_at),
    ADD KEY idx_logs_module_action_created (module_key, action, created_at),
    ADD KEY idx_logs_correlation (correlation_id);

-- LONGTEXT is intentional for MariaDB 10.4 compatibility. Future application
-- code must store valid JSON when before_state or after_state is populated.
