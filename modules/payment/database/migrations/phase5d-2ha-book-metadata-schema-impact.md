# Phase 5D-2H-A Book Metadata Schema Impact

## Sakop

Additive table lang ang `school_sale_book_details` sa production Payment DB. Descriptive metadata lang ito para sa existing `BOOK` catalog items. Walang application behavior, data seed, o Cashier enablement sa subphase na ito.

## Proposed schema

- `sale_book_detail_id BIGINT UNSIGNED AUTO_INCREMENT` — surrogate history/detail-style primary key.
- `sale_item_id INT UNSIGNED NOT NULL` — one-to-one owner, unique, at restrictive FK sa `school_sale_items.sale_item_id`.
- `book_title VARCHAR(255) NOT NULL`.
- Nullable descriptive fields: `author VARCHAR(255)`, `publisher VARCHAR(255)`, `edition VARCHAR(100)`, `isbn VARCHAR(32)`, at `notes TEXT`.
- `created_by` at `updated_by` are nullable `INT UNSIGNED` soft actor references, consistent sa catalog tables.
- `created_at` at `updated_at` follow the existing School Sales timestamp convention.
- Storage: `InnoDB`, `utf8mb4`, `utf8mb4_uca1400_ai_ci`.

## Constraints and indexes

- PK: `sale_book_detail_id`.
- One-to-one unique key: `uq_school_sale_book_item (sale_item_id)`.
- Lookup index: `idx_school_sale_book_isbn (isbn)`; deliberately nonunique because uniqueness/checksum policy belongs to the later service contract.
- FK: `fk_school_sale_book_item`, `ON DELETE RESTRICT ON UPDATE RESTRICT`.
- CHECK constraints reject blank required/optional strings and malformed ISBN character/length shapes. Exact ISBN checksum validation remains application responsibility.

## Safety boundaries

- Migration requires Hostforge MariaDB `11.8.8`, database `hf_db_bfim0j6t`, exact parent PK/storage conventions, and the exact controlled `BOOK` lookup row.
- Any existing target, partial target, conflicting School Sales Book table, or Book columns embedded in `school_sale_items` blocks automatic migration.
- No pricing, stock, inventory, quantity, billing, assessment, AR, debt, payment, allocation, receipt, or Cashier-sale fields are introduced.
- No item rows or Book metadata rows are seeded.
- Existing BOOK activation block remains in application code until the later approved Book service phase.
- Rollback refuses to drop the table when even one metadata row exists.

## Execution order for the later approved deployment

1. Verify production backup and restore readiness.
2. Run `phase5d-2ha-book-metadata-preflight.sql` read-only.
3. Continue only when every check is `PASS` and deployment state is `FRESH`.
4. Run `phase5d-2ha-book-metadata-migration.sql` once.
5. Run `phase5d-2ha-book-metadata-validation.sql` read-only.
6. Do not run rollback after a successful deployment.

