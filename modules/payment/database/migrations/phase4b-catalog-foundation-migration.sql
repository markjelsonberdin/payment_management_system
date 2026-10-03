-- Phase 4B: School Sales catalog foundation (TEST DATABASE ONLY).
-- Execute only after the Phase 4B preflight passes and the core audit extension
-- has been applied successfully. This migration contains no seed data.
-- It deliberately excludes receipt tables, variants, prices, applicability rows,
-- cash_sales, cash_sale_items, fees, fee_categories, and billing tables.
USE payment_db_test;

CREATE TABLE school_sale_categories (
    sale_category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_code VARCHAR(60) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('Draft', 'Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_category_id),
    UNIQUE KEY uq_school_sale_category_code (category_code),
    KEY idx_school_sale_category_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE school_sale_item_types (
    sale_item_type_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_code VARCHAR(60) NOT NULL,
    type_name VARCHAR(120) NOT NULL,
    metadata_profile VARCHAR(60) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_item_type_id),
    UNIQUE KEY uq_school_sale_item_type_code (type_code),
    UNIQUE KEY uq_school_sale_item_type_name (type_name),
    KEY idx_school_sale_item_type_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE school_sale_items (
    sale_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_code VARCHAR(60) NOT NULL,
    sale_category_id INT UNSIGNED NOT NULL,
    sale_item_type_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    applicability_mode ENUM('ALL', 'RESTRICTED') NOT NULL DEFAULT 'ALL',
    status ENUM('Draft', 'Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_item_id),
    UNIQUE KEY uq_school_sale_item_code (item_code),
    KEY idx_school_sale_item_category_status (sale_category_id, status),
    KEY idx_school_sale_item_type_status (sale_item_type_id, status),
    KEY idx_school_sale_item_name (item_name),
    CONSTRAINT fk_school_sale_item_category
        FOREIGN KEY (sale_category_id) REFERENCES school_sale_categories (sale_category_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_school_sale_item_type
        FOREIGN KEY (sale_item_type_id) REFERENCES school_sale_item_types (sale_item_type_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- All items default to Draft. Do not insert catalog data or permit Active items
-- until Phase 5 supplies a variant and an effective server-authoritative price.
