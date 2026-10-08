# Batch 4J allocation constraint deployment notes

These artifacts are proposals only and were not executed.

## Prerequisites

- Batch 4E must confirm the Hostforge schema, column types, trigger definitions, and MariaDB version.
- Every preflight violation count must be zero.
- Back up the Payment database and verify restore access.
- Run the migration first against an isolated production-like test database.
- Pause payment posting during the maintenance window.

## Lock implications

Each `ALTER TABLE` requires a metadata lock. The server version and table layout determine whether the operation is instant, in-place, or rebuilds the table. Measure duration on a production-sized copy before scheduling.

## Trigger compatibility

The current allocation insert trigger increases `billing_items.paid_amount`; billing-item update triggers recalculate `remaining_amount`. Validation must confirm their order produces values accepted by the proposed checks.

## Rollback limitations

Rollback removes only the constraints. It does not reverse financial writes, reconstruct balances, or repair historical violations. Any failed preflight row requires a separately authorized reconciliation process.

## Isolated tests required

Test positive Cashier, live QR Ph, and approved concern allocations; zero and negative values; overpayment; trigger failure rollback; duplicate allocation; concurrent posting; and migration rollback. Do not run the migration against production until those MariaDB tests pass.
