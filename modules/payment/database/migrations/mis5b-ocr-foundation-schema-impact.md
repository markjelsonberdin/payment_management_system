# MIS-5B OCR database foundation

## Scope

This additive design creates four tables and does not alter payment_concerns or ocr_results. The verified parent key is payment_concerns.concern_id INT(10) UNSIGNED NOT NULL PRIMARY KEY on InnoDB. Core user identifiers remain snapshots without cross-database foreign keys.

## Responsibilities

- ocr_usage_months is the authoritative Asia/Manila monthly quota row. configured_limit snapshots the active MIS limit when the row is created.
- ocr_usage_ledger stores one durable operation identity and lifecycle. request_id is a server UUID v4 for tracing. idempotency_key is a server-generated SHA-256 over operation, concern ID, immutable receipt identity/hash, provider, feature, and feature version.
- ocr_scan_attempts preserves every attempt and normalized text separately from parser output. It stores version metadata, safe quality metadata, failures, and cache provenance. No full Google provider payload is added.
- ocr_image_cache maps the composite image/provider/feature/feature-version key to a successful canonical attempt.

## Atomic reservation

Begin a transaction, create the month row with INSERT IGNORE using the configured limit snapshot, then SELECT it FOR UPDATE. Reject if reserved_units + consumed_units + 1 exceeds configured_limit. Otherwise increment reserved_units and row_version, insert the RESERVED ledger row and attempt, and commit. Duplicate idempotency inserts return the existing operation. Before the provider boundary, transition one reserved unit to consumed, set provider_called and its timestamp, then commit. A pre-call failure decrements reserved and increments released. Provider success increments successful; provider failure increments failed. Both remain consumed. Cache hits use zero units and never touch monthly counters.

At 899/900, concurrent transactions serialize on the same month row: one commits unit 900 and the next observes capacity exhausted. A MariaDB two-worker test is required after migration.

## Existing ocr_results compatibility

ocr_results remains the readable legacy/latest projection for existing callers. New immutable attempts become the evidence history. Later MIS-5C code may update the legacy projection after a successful parsed attempt, but does not delete or destructively backfill old rows. raw_json remains legacy and will not receive new full provider payloads.

## Lifecycle and fail-safe states

Ledger states are RESERVED, PROVIDER_CALLED, SUCCEEDED, FAILED, RELEASED, REJECTED_LIMIT, and CACHE_HIT. Failure categories distinguish configuration, authentication, quota, invalid receipt, transient provider, permanent provider, and manual-review outcomes. OCR states remain separate from Accounting verification.

## Impact, backup, risk, rollback

Impact is four additive InnoDB tables, six foreign keys, four unique business indexes, and supporting indexes/checks. Risk is medium because DDL implicitly commits and quota correctness depends on transactional application code. Before execution, record and verify a payment_db backup filename, timestamp, target database, and restore/readability result. Rollback drops cache, attempts, ledger, then months and removes only MIS-5B additions.

## Regression after future migration

Run structural validation, duplicate-idempotency tests, cache-context tests, cache-hit zero-unit tests, invalid concern and authorization tests, safe provider-error tests, and a real two-connection 899/900 boundary test using a mocked provider. No Google Vision request is needed.
