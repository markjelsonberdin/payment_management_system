ALTER TABLE `bank_statement_rows`
ADD UNIQUE INDEX `idx_unique_transaction` (`reference_number`, `transaction_date`, `transaction_time`, `amount`);
