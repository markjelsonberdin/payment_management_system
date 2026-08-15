-- =========================================================
-- PHASE 2: DATABASE TRIGGERS
-- Architecture: payment_allocations -> billing_items 
-- (Billing summary updates are pushed to PHP transaction layer)
-- =========================================================

DELIMITER //

-- ---------------------------------------------------------
-- 1. BILLING ITEMS TRIGGERS
-- ---------------------------------------------------------

CREATE TRIGGER `before_billing_items_insert` BEFORE INSERT ON `billing_items`
FOR EACH ROW
BEGIN
    -- Prevent over-payment at DB level
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    -- Recalculate remaining_amount
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    -- Set Status
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END//

CREATE TRIGGER `before_billing_items_update` BEFORE UPDATE ON `billing_items`
FOR EACH ROW
BEGIN
    -- Prevent over-payment at DB level
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    -- Recalculate remaining_amount
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    -- Set Status
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END//

-- ---------------------------------------------------------
-- 2. BILLING TRIGGERS
-- ---------------------------------------------------------

CREATE TRIGGER `before_billing_insert` BEFORE INSERT ON `billing`
FOR EACH ROW
BEGIN
    -- Initialize remaining balance based on Gross Assessment - Discount
    SET NEW.remaining_balance = GREATEST(0, NEW.total_amount - NEW.discount_amount);
    
    IF NEW.remaining_balance <= 0 THEN
        SET NEW.billing_status = 'Paid';
    ELSE
        SET NEW.billing_status = 'Unpaid';
    END IF;
END//

-- ---------------------------------------------------------
-- 3. PAYMENT ALLOCATIONS TRIGGERS
-- ---------------------------------------------------------

CREATE TRIGGER `after_payment_allocations_insert` AFTER INSERT ON `payment_allocations`
FOR EACH ROW
BEGIN
    -- Push the allocation amount up to the billing item
    -- (This will fire the `before_billing_items_update` trigger)
    UPDATE `billing_items`
    SET `paid_amount` = `paid_amount` + NEW.allocated_amount
    WHERE `billing_item_id` = NEW.billing_item_id;
END//

DELIMITER ;
