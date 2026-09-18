-- PAYMENT DATABASE ONLY. Apply once after a backup.
-- Lets Accounting run the same fee for different year levels in one AY/semester.
ALTER TABLE `fee_billing_campaigns`
  DROP INDEX `uq_fee_term`,
  ADD UNIQUE KEY `uq_fee_term_year_level` (`fee_id`, `academic_year`, `semester`, `year_level`);
