# SMS2 Payment Portal Separation & Automated Billing

## Implementation summary

Implemented and pushed in commit `8fa57b9`:

- Separated Payment responsibilities between Finance Admin, Accounting Officer, Cashier, and Student.
- Added Accounting Officer access using the existing `accounting_officer` Core role.
- Restricted Cashier access to walk-in collection and cashier-scoped walk-in history.
- Preserved Finance Admin ownership of Fee Setup and Online Payment Integration.
- Added fee submission, Accounting review, eligibility preview, and guarded bulk-billing workflow.
- Reused the existing `BillingService`; no second billing or allocation engine was created.
- Added Payment notifications to the existing notification bell flow.
- Preserved PayMongo, QRPh, Google OCR, AUB reconciliation, and `PaymentAllocationService` behavior.

## Role responsibilities

| Role | Responsibilities |
|---|---|
| Finance Admin (`finance`) | Fee Setup, Online Payment Integration, Payment staff accounts, administrative monitoring |
| Accounting Officer (`accounting_officer`) | Fee review, billing approval/generation, discounts, ledger, analytics, OCR/payment concerns, AUB reconciliation |
| Cashier (`cashier`) | Walk-in payment processing and own walk-in transaction history |
| Student (`student`) | Statement of Account, balance, existing online payment, payment concerns, payment history, notifications |

The `accounting_officer` role already existed in the live Core database. Its permissions were added through `database/migrations/payment_accounting_officer_grants.sql`. The old Cashier accounting permissions were disabled while `payment.collection` and `payment.walkin_history` were retained.

## Fee and billing workflow

```text
Finance Admin creates fee
        ↓
Admin selects academic year and semester
        ↓
Submit for Accounting review
        ↓
Accounting Officer notification
        ↓
Eligibility preview
        ↓
Accounting approves
        ↓
Existing BillingService adds billing item
        ↓
Student receives notification
        ↓
Student pays online or through Cashier
```

Fee creation alone does not charge students. Accounting approval is the financial gate.

### Current eligibility rule

Because Registrar/Enrollment integration is not yet available, v1 uses only:

```text
students.status = 'Enrolled'
```

Course, year level, and subject enrollment are not used as authoritative criteria yet. The system must not infer those values from placeholder Student API data. Registrar integration can add more precise cohort rules in a future phase.

## Database migrations

### Payment database

`modules/payment/database/migrations/add_fee_billing_workflow.sql` adds:

- `fee_billing_campaigns` — submitted fee configuration, term, status, approval, progress, and totals.
- `fee_billing_assignments` — per-student outcome and duplicate protection for a campaign.
- `payment_notifications` — Payment-specific recipient notifications and read state.

These are additive tables. Existing payments, allocations, billing balances, and billing items are not rewritten.

### Core SMS2 database

`database/migrations/payment_accounting_officer_grants.sql` adds Accounting Officer permission grants and the Cashier walk-in-history permission. The `accounting_officer` role itself was already present.

## Code changes

- `config/config.php`: Accounting and Cashier navigation groups.
- `includes/authentication.php`: role-aware granular Payment permission checks.
- `modules/payment/includes/PaymentSecurityService.php`: granular authorization and correct `billing` ownership check.
- `modules/payment/includes/FeeBillingWorkflow.php`: campaign submission, preview, approval, resumable chunks, duplicate handling, summaries, and notifications.
- `modules/payment/pages/admin/fee-setup-configuration.php`: fee review submission UI.
- `modules/payment/pages/accounting/student-billing-invoicing.php`: Accounting campaign list, preview, approval, processing, and retry UI.
- `modules/payment/includes/PaymentHistoryService.php`: Cashier walk-in and processor scoping.
- `modules/payment/pages/accounting/walk-in-transaction-history.php`: Cashier view using the existing history page.
- `includes/notifications.php`, `api/notifications.php`, and `includes/navbar.php`: Payment notification retrieval and read handling.
- Payment APIs: granular permission guards for collection, discount, billing search, dashboard, OCR, receipt viewing, and AUB import.

## Safety controls

- Bulk generation requires `PAYMENT_BULK_BILLING_ENABLED="1"`.
- The example configuration defaults this value to `0`.
- Generation rechecks the fee snapshot, term, eligibility, and Accounting permission.
- Processing is chunked and resumable.
- A campaign/student pair is unique, preventing repeated approval from creating duplicate assignments.
- Existing billing items are checked before insertion.
- Student notifications are created only after a successful billing-item insert.
- Cashier history is filtered server-side by `transaction_type = 'Walk-in'` and `verified_by = current user`.

## Deployment checklist

1. Confirm the code deployment contains commit `8fa57b9`.
2. Confirm the three Payment workflow tables exist in `payment_db`.
3. Confirm Accounting Officer permissions in `sms2_db`.
4. Create or assign an active Accounting Officer user account.
5. Test direct URL access as Finance Admin, Accounting Officer, Cashier, and Student.
6. Test fee submission and Accounting preview with bulk generation still disabled.
7. Verify the Payment student cache contains reliable enrolled-status records.
8. Only after review, set `PAYMENT_BULK_BILLING_ENABLED=1` in the protected deployment environment.
9. Run one small controlled campaign and verify the billing item, notification, SOA, and payment paths.

## Verification performed

- PHP lint passed for all changed PHP files.
- `git diff --check` passed without whitespace errors.
- Code was committed and pushed to `origin/main`.
- Live database screenshots confirmed all three Payment workflow tables and Accounting Officer grants.

## Rollback notes

Disable `PAYMENT_BULK_BILLING_ENABLED` first. Do not delete generated billing items or payment records automatically. Review affected students and financial allocations with Accounting. Code rollback and permission restoration must be coordinated with the database state.

