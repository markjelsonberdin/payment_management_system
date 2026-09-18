-- PAYMENT DATABASE ONLY. Apply after add_fee_billing_workflow.sql and
-- allow_fee_billing_per_year_level.sql. Back up the database first.
-- Preserves legacy per-fee campaigns while enabling one campaign per category.

ALTER TABLE `fee_billing_campaigns`
  MODIFY `fee_id` int(10) unsigned DEFAULT NULL,
  ADD COLUMN `category_id` int(10) unsigned DEFAULT NULL AFTER `fee_id`,
  ADD COLUMN `category_name_snapshot` varchar(100) DEFAULT NULL AFTER `category_id`,
  ADD KEY `idx_campaign_category` (`category_id`),
  ADD UNIQUE KEY `uq_category_term_year_level` (`category_id`, `academic_year`, `semester`, `year_level`),
  ADD CONSTRAINT `fk_campaign_category` FOREIGN KEY (`category_id`) REFERENCES `fee_categories` (`category_id`);

CREATE TABLE `fee_billing_campaign_items` (
  `campaign_item_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `fee_id` int(10) unsigned NOT NULL,
  `fee_name_snapshot` varchar(100) NOT NULL,
  `amount_snapshot` decimal(10,2) NOT NULL,
  PRIMARY KEY (`campaign_item_id`),
  UNIQUE KEY `uq_campaign_fee` (`campaign_id`, `fee_id`),
  CONSTRAINT `fk_campaign_item_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `fee_billing_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaign_item_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
