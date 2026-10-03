# C4 Cashier Transaction Upgrade — Schema Impact

## Purpose and boundary

C4 adds a Cashier-owned orchestration and School Sales reversal layer. It does not change Accounting Admin catalog configuration, `payments` posting semantics, `PaymentAllocationService`, `payment_allocations`, `billing_items`, or `billing`.

`modules/payment/database/payment.sql` is the authoritative target-schema reference for the existing catalog and legacy Cashier baseline. It is a full dump containing destructive statements and must never be executed as a migration.

## Dependency gate

The migration requires the Accounting Admin catalog foundation:

- `school_sale_categories`
- `school_sale_item_types`
- `school_sale_items`
- `school_sale_item_variants`
- `school_sale_variant_prices`
- `school_sale_item_applicability`
- `school_sale_book_details`

It also requires `students`, `payments`, `payment_allocations`, `billing`, `billing_items`, and `payment_audit_outbox`.

The connected development database currently lacks the catalog foundation. Running the C4 preflight now must report `FAIL: accounting catalog foundation missing`; this is the intended fail-closed outcome. The migration must not be run until all preflight checks pass.

## C4 objects

| Object | Change | Compatibility |
|---|---|---|
| `official_receipt_sequences` | Create only when absent, using the baseline target definition | No data is seeded or overwritten. |
| `cash_sales` | Create only when absent; otherwise add nullable `cashier_transaction_id` | Legacy sale rows remain valid. |
| `cash_sale_items` | Create only when absent; otherwise add nullable catalog/variant/price/applicability/Book snapshots | Existing lines retain their original snapshots and null C4 columns. |
| `cashier_receipt_print_events` | Create only when absent; extend legacy enum and add nullable transaction/void references | Existing payment/sale events remain readable. |
| `cashier_transactions` | New immutable completed checkout header | Canonical owner of new checkout transaction number and original OR. |
| `cashier_transaction_fee_payments` | New one-to-one link to existing `payments` row | No allocation behavior is changed. |
| `cashier_sale_voids` | New immutable School Sales cash-refund header | References original transaction and sale; does not mutate either. |
| `cash_sale_line_reversals` | New immutable full-line reversal records | One reversal per original sale line. |

## Integrity model

- Transaction number, original OR, `(cashier_user_id, idempotency_key)`, refund-slip number, and original sale-line reversal each have database uniqueness guarantees.
- Correlation IDs follow the existing UUID v4 convention. Their global uniqueness remains owned by `payment_audit_outbox`; C4 adds non-unique lookup indexes only.
- New `cash_sale_items` lines will retain catalog references and full immutable snapshots. Runtime code will later enforce that snapshots are populated only after locking and revalidating the authoritative catalog.
- Quick Void is School-Sales-only, full-line-only, immediate-cash-refund-only, and immutable. There is no partial-quantity field, replacement/netting field, stock field, or academic payment reversal path.

## Deployment sequence

1. Confirm restorable backup of the Payment database.
2. Deploy/validate the Accounting Admin catalog foundation separately.
3. Run `cashier-transaction-preflight.sql` and retain its PASS output.
4. Obtain separate approval to execute `cashier-transaction-migration.sql`.
5. Run `cashier-transaction-validation.sql` immediately after migration.
6. Keep `PAYMENT_SCHOOL_SALES_SELLING_ENABLED=false` until later runtime, concurrency, receipt, and end-to-end validation phases pass.

## Rollback limits

`cashier-transaction-rollback.sql` is schema-only and refuses to proceed if any C4 transaction, linkage, reversal, or C4 receipt-print record exists. It retains baseline Cashier tables and never changes catalog, payment, allocation, billing, audit-outbox, or historical records.

## Out of scope

No runtime/UI implementation, receipt rendering, transaction posting, Quick Void endpoint, stock handling, partial quantity reversal, academic payment reversal, replacement sale, deployment, or feature-flag enablement is included in C4.
