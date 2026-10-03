# Phase 5B Catalog Audit Outbox Contract

## Layunin

Magkaibang Hostforge databases/hosts ang Payment mutation at Core `activity_logs`, kaya walang cross-host atomic transaction. Required ang durable `payment_audit_outbox`: catalog mutation at outbox insert commit together sa Payment DB, then asynchronous delivery to Core.

## Identity at deduplication

- `correlation_id`: globally unique at may unique index sa Payment outbox at Core `activity_logs`.
- `request_fingerprint`: required SHA-256 comparison value, indexed pero deliberately nonunique.
- Same correlation + same fingerprint: return stored committed result; delivery may resume if unresolved.
- Same correlation + different fingerprint: permanent conflict; never overwrite either request or result.
- Same fingerprint + different correlation: valid independent mutations.

## Delivery lifecycle

`Pending -> Processing -> Delivered` ang normal path. Temporary error returns to `Pending` with incremented attempts, last error, at next attempt. Exhausted/operator-required delivery becomes `Failed`; reconciliation may explicitly return it to `Pending` without changing identity or payload.

Worker claim must be atomic and concurrency-safe. Bago magsulat sa Core, use the correlation ID as the idempotency key. A duplicate-key response from Core counts as success only if the existing Core event matches the immutable outbox event identity and payload; otherwise raise a reconciliation conflict.

Suggested retry cadence: immediate, 1 minute, 5 minutes, 15 minutes, 60 minutes, then hourly. After 10 failed attempts mark `Failed` and alert. This schedule is an application contract, not SQL behavior.

## Cross-host failure result

Kapag committed na ang Payment mutation pero unavailable ang Core:

1. Keep the outbox row as Pending/Failed.
2. Surface `correlation_id`, committed result, at audit delivery status to the caller.
3. Never repeat or compensate the business mutation solely because audit delivery failed.
4. Retry from the durable outbox.
5. Reconciliation reports unresolved Pending/Processing-stale/Failed rows by age and attempt count.

Stale `Processing` claims must be recoverable through an implementation-defined lease/timeout using `last_attempt_at`; recovery must not create a second business mutation.

## Retention

Delivered rows remain indefinitely in Phase 5B. Walang scheduled purge, delete-on-delivery, TTL, o cleanup job. Durable idempotency-retention policy is deferred. Any future purge requires a separately approved retention horizon and proof that replay guarantees remain valid. Rollback must refuse while any outbox row exists, including Delivered.

## Operational reconciliation

Required later tooling/monitoring:

- counts and oldest age by delivery status;
- alert for stale Processing and Failed rows;
- correlation lookup across Payment and Core;
- payload/fingerprint mismatch escalation;
- controlled retry that preserves row identity and history fields.

## Verification boundary

Phase 5B SQL can prove schema, lifecycle enum, unique correlation, and nonunique fingerprint index. Worker locking, Core comparison, retries, alerting, replay semantics, and reconciliation remain unverified until application implementation and tests.
