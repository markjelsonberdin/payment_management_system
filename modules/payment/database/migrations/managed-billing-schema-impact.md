# Managed Billing Schema Impact (Phase 2)

These are additive, unapplied migration artifacts. `billing_items.fee_version_id` is nullable, indexed, unique per billing/version, and restrictively references `fee_versions`; historical rows remain NULL and receive no backfill.

| Object | Purpose and impact | Risk / rollback |
| --- | --- | --- |
| `billing_items.fee_version_id` | Links future managed items to the selected fee version; affects only schema, not existing rows. | Medium DDL/index lock risk; pre-use only removal after zero non-NULL values. |
| `managed_assessment_headers` | Canonical managed Standard Assessment mapping per student/term. No legacy mapping. | Low compatibility risk; restrictive FKs; empty-table drop only before use. |
| `billing_runs` | Run lifecycle and reconcilable summary/cache values. | Low legacy risk; assignment detail remains authoritative. |
| `billing_run_fee_versions` | Records run fee-version decisions and preview snapshots. | Required Standard rows cannot be excluded; service reloads authoritative versions at commit. |
| `billing_run_assignments` | Detailed source of truth for every run/student outcome. | `NoApplicableFees` is successful processing, not failure. |
| `billing_notification_outbox` | Durable notification intent before writing student-facing `payment_notifications`. | One intent per event key and per run/student; no blind `INSERT IGNORE` in future delivery. |

## Rules and compatibility

- `billing_runs` counters are reconciled caches; `billing_run_assignments` is authoritative.
- `processing_cursor` is a processing optimization, never proof of prior success.
- Required + Standard must be selected. Standard non-required is reviewable. One-Time requires later service-level lifetime-history validation. Optional and Manual are excluded from normal Bulk Standard Assessment.
- All managed FKs use `RESTRICT`; no cascade delete is introduced. Existing billing, billing items, payment allocations, fee versions, applicability, notifications, and legacy services are unaffected.
- Before execution, run the read-only preflight on the target and stop for any schema-parity mismatch or partial-migration object.

## Ordered DDL recovery

MariaDB DDL is not treated as atomic. Verify each step after application. On failure: stop, record completed/failed step and SQL error, run partial-migration preflight, and only recover objects confirmed empty. The rollback artifact lists reverse staged recovery. After managed records exist, use a separately approved preservation migration; do not destructively roll back.
