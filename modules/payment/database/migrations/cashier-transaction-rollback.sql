-- C4 Cashier Transaction Upgrade rollback.
-- Run only with separate rollback approval and a verified backup.
-- It refuses to remove the schema when C4 financial or receipt data exists.

DROP PROCEDURE IF EXISTS c4_assert_cashier_transaction_rollback_safe;
DELIMITER //
CREATE PROCEDURE c4_assert_cashier_transaction_rollback_safe()
BEGIN
    DECLARE transaction_rows BIGINT DEFAULT 0;
    DECLARE fee_link_rows BIGINT DEFAULT 0;
    DECLARE void_rows BIGINT DEFAULT 0;
    DECLARE reversal_rows BIGINT DEFAULT 0;
    DECLARE print_rows BIGINT DEFAULT 0;

    SELECT COUNT(*) INTO transaction_rows FROM cashier_transactions;
    SELECT COUNT(*) INTO fee_link_rows FROM cashier_transaction_fee_payments;
    SELECT COUNT(*) INTO void_rows FROM cashier_sale_voids;
    SELECT COUNT(*) INTO reversal_rows FROM cash_sale_line_reversals;
    SELECT COUNT(*) INTO print_rows FROM cashier_receipt_print_events WHERE cashier_transaction_id IS NOT NULL OR cashier_sale_void_id IS NOT NULL;

    IF transaction_rows <> 0 OR fee_link_rows <> 0 OR void_rows <> 0 OR reversal_rows <> 0 OR print_rows <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C4 rollback blocked: C4 financial or receipt records exist.';
    END IF;
END//
DELIMITER ;

CALL c4_assert_cashier_transaction_rollback_safe();
DROP PROCEDURE c4_assert_cashier_transaction_rollback_safe;

ALTER TABLE cashier_receipt_print_events
    DROP FOREIGN KEY fk_receipt_print_transaction,
    DROP FOREIGN KEY fk_receipt_print_void,
    DROP INDEX idx_receipt_print_transaction,
    DROP INDEX idx_receipt_print_void,
    DROP COLUMN cashier_transaction_id,
    DROP COLUMN cashier_sale_void_id,
    MODIFY COLUMN record_type ENUM('payment','cash_sale') NOT NULL;

DROP TABLE cash_sale_line_reversals;
DROP TABLE cashier_sale_voids;

ALTER TABLE cash_sale_items
    DROP FOREIGN KEY fk_cash_sale_line_variant,
    DROP FOREIGN KEY fk_cash_sale_line_price,
    DROP INDEX idx_cash_sale_line_variant,
    DROP INDEX idx_cash_sale_line_price,
    DROP COLUMN sale_variant_id,
    DROP COLUMN sale_variant_price_id,
    DROP COLUMN item_code_snapshot,
    DROP COLUMN category_code_snapshot,
    DROP COLUMN item_type_code_snapshot,
    DROP COLUMN item_type_name_snapshot,
    DROP COLUMN variant_code_snapshot,
    DROP COLUMN variant_name_snapshot,
    DROP COLUMN size_label_snapshot,
    DROP COLUMN price_effective_from_snapshot,
    DROP COLUMN price_effective_to_snapshot,
    DROP COLUMN applicability_mode_snapshot,
    DROP COLUMN student_program_snapshot,
    DROP COLUMN student_year_level_snapshot,
    DROP COLUMN applicability_result_snapshot,
    DROP COLUMN book_title_snapshot,
    DROP COLUMN book_author_snapshot,
    DROP COLUMN book_publisher_snapshot,
    DROP COLUMN book_edition_snapshot,
    DROP COLUMN book_isbn_snapshot;

ALTER TABLE cash_sales
    DROP FOREIGN KEY fk_cash_sale_cashier_transaction,
    DROP INDEX uq_cash_sales_cashier_transaction,
    DROP COLUMN cashier_transaction_id;

DROP TABLE cashier_transaction_fee_payments;
DROP TABLE cashier_transactions;

-- Baseline Cashier tables from payment.sql are deliberately retained. They may predate C4.
