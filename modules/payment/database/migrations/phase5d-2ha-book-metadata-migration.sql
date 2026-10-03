-- Phase 5D-2H-A additive Book metadata migration.
-- Target: Hostforge Payment DB hf_db_bfim0j6t, MariaDB 11.8.8.
-- Execute only when phase5d-2ha-book-metadata-preflight.sql reports FRESH and all checks PASS.
-- No USE statement: connect directly to the target database.

DROP PROCEDURE IF EXISTS phase5d_2ha_assert_book_metadata_safe;
DELIMITER //
CREATE PROCEDURE phase5d_2ha_assert_book_metadata_safe()
BEGIN
    DECLARE target_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE conflicting_tables BIGINT UNSIGNED DEFAULT 0;
    DECLARE conflicting_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE parent_columns BIGINT UNSIGNED DEFAULT 0;
    DECLARE parent_storage BIGINT UNSIGNED DEFAULT 0;
    DECLARE exact_book_types BIGINT UNSIGNED DEFAULT 0;

    IF DATABASE() <> 'hf_db_bfim0j6t' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A blocked: wrong Payment database.';
    END IF;
    IF VERSION() NOT LIKE '11.8.8-MariaDB%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A blocked: unverified MariaDB version.';
    END IF;

    SELECT COUNT(*) INTO target_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_book_details';

    SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('school_sale_books', 'school_sale_item_book_details',
                         'school_sales_book_details', 'school_sale_book_metadata');

    SELECT COUNT(*) INTO conflicting_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_items'
      AND column_name IN ('book_title', 'author', 'publisher', 'edition', 'isbn',
                          'book_notes', 'book_metadata');

    SELECT COUNT(*) INTO parent_columns
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_items'
      AND column_name = 'sale_item_id' AND column_type = 'int(10) unsigned'
      AND is_nullable = 'NO' AND column_key = 'PRI';

    SELECT COUNT(*) INTO parent_storage
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'school_sale_items'
      AND engine = 'InnoDB' AND table_collation = 'utf8mb4_uca1400_ai_ci';

    SELECT COUNT(*) INTO exact_book_types
    FROM school_sale_item_types
    WHERE type_code = 'BOOK' AND type_name = 'Book'
      AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active';

    IF target_tables <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A blocked: target table already exists; validate instead of rerunning.';
    END IF;
    IF conflicting_tables <> 0 OR conflicting_columns <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A blocked: conflicting Book metadata structure exists.';
    END IF;
    IF parent_columns <> 1 OR parent_storage <> 1 OR exact_book_types <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5D-2H-A blocked: parent catalog or BOOK lookup is incompatible.';
    END IF;
END//
DELIMITER ;

CALL phase5d_2ha_assert_book_metadata_safe();
DROP PROCEDURE phase5d_2ha_assert_book_metadata_safe;

CREATE TABLE school_sale_book_details (
    sale_book_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_item_id INT UNSIGNED NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NULL,
    publisher VARCHAR(255) NULL,
    edition VARCHAR(100) NULL,
    isbn VARCHAR(32) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_book_detail_id),
    UNIQUE KEY uq_school_sale_book_item (sale_item_id),
    KEY idx_school_sale_book_isbn (isbn),
    CONSTRAINT fk_school_sale_book_item
        FOREIGN KEY (sale_item_id) REFERENCES school_sale_items (sale_item_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_school_sale_book_title_nonblank
        CHECK (CHAR_LENGTH(TRIM(book_title)) > 0),
    CONSTRAINT chk_school_sale_book_author_nonblank
        CHECK (author IS NULL OR CHAR_LENGTH(TRIM(author)) > 0),
    CONSTRAINT chk_school_sale_book_publisher_nonblank
        CHECK (publisher IS NULL OR CHAR_LENGTH(TRIM(publisher)) > 0),
    CONSTRAINT chk_school_sale_book_edition_nonblank
        CHECK (edition IS NULL OR CHAR_LENGTH(TRIM(edition)) > 0),
    CONSTRAINT chk_school_sale_book_isbn_format
        CHECK (isbn IS NULL OR (CHAR_LENGTH(TRIM(isbn)) BETWEEN 10 AND 32
               AND isbn REGEXP '^[0-9Xx -]+$')),
    CONSTRAINT chk_school_sale_book_notes_nonblank
        CHECK (notes IS NULL OR CHAR_LENGTH(TRIM(notes)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- This migration inserts no Book metadata and does not alter the BOOK activation block.

