# Payment reconciliation permission rollout

These capabilities are stored in the Core SMS2 role_permissions table. They do not belong in payment_db.

| Role | Granted capabilities |
| --- | --- |
| Accounting Officer | payment.reconciliation.view, payment.reconciliation.process, payment.reconciliation.import |
| Accounting Admin | payment.reconciliation.view, payment.reconciliation.exception.approve |

The role bundle remains the authorization ceiling. An explicit Core row can revoke an allowed capability by setting granted = 0, but a row cannot grant a capability to a role outside the bundle. The idempotent migration preserves an existing row, including an existing granted = 0 revocation.

The four reconciliation capabilities are strict: a missing exact row denies access. This differs from older Payment capabilities, where a missing granular row temporarily falls back to the role bundle for compatibility. Core database errors also deny access.

Apply payment_reconciliation_permissions.sql as part of the same controlled release as the code. Apply it before directing staff to the reconciliation routes. The existing concern-review capability remains separate because concern verification can create, verify, and allocate an official payment.

payment.reconciliation.exception.approve defines the Accounting Admin ceiling only. No exception approval endpoint or state transition currently exists, so this batch does not add one. A future workflow requires a separate schema and state-machine proposal, including self-approval prevention, before implementation.

Rollback removes only the five new role-capability mappings. After rollback, strict missing-row behavior blocks all reconciliation access until the migration is reapplied.
