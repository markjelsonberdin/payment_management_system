-- Reverse migration for add_fee_setup_database_foundation.sql.
-- Safe only before application code or business data depends on the new schema.

DROP TABLE `fee_applicability`;
DROP TABLE `fee_versions`;

ALTER TABLE `fees`
  DROP FOREIGN KEY `fk_fees_fee_type`,
  DROP INDEX `idx_fees_fee_type`,
  DROP INDEX `uq_fees_fee_code`,
  DROP COLUMN `identity_status`,
  DROP COLUMN `fee_type_id`,
  DROP COLUMN `fee_code`;

DROP TABLE `fee_types`;
DROP TABLE `fee_groups`;
