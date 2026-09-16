---
name: payment-security
description: Audit, threat-model, and—only after explicit Cycle B approval—harden security inside SMS2 Payment Management: access control, requests, financial integrity, PayMongo, receipts/OCR, bank reconciliation, secrets, data protection, audit, and monitoring. Do not redesign global SMS2 security or begin implementation from an audit request alone.
---

# Payment Security

## Purpose

Protect Payment-owned workflows and data without replacing SMS2 Core authentication, sessions, global RBAC, password, or system-wide security architecture.

## Required Context

Before any work, read:

- [Repository agent guide](../../AGENTS.md)
- [Payment rules](../../rules/payment-system.md)
- [Cycle B security plan](../../payment-module-security-implementation-plan.md)
- [Cycle A plan](../../payment-module-implementation-plan.md) when a control affects current workflow

Inspect actual endpoints, callers, services, schema, deployment configuration, and existing enforcement. Never infer a control from UI visibility or documentation alone.

## Authorization Boundary

- An audit/review request authorizes read-only inspection and reporting, not remediation.
- Cycle B implementation requires a fresh explicit approval after the audit/threat model.
- Database changes, credential rotation/revocation, destructive cleanup, deployment, and LIVE testing each require separate authority.

## Scope

- Payment endpoint permissions, ownership/IDOR, direct URL/API access
- CSRF, validation, prepared statements, rate limiting, and safe errors
- Duplicate/concurrency/state/allocation integrity
- PayMongo signature, replay/idempotency, amount/currency/context/environment checks, and secret handling
- Secure receipt upload/access, OCR permissions/evidence, and Google credentials
- Bank import validation, duplication, authorization, and audit
- Payment lifecycle/security logs, data minimization, encryption/retention design, monitoring, and evidence-based status UI

## Workflow

1. Confirm audit-only versus explicitly approved implementation.
2. Build endpoint/caller, role/permission/object, trust-boundary, secret-status, and sensitive-data inventories.
3. Threat-model Student, Cashier, Accounting, Admin, compromised sessions, forged webhooks, malicious uploads, and concurrency.
4. Verify controls using code/config evidence and classify P0–P3.
5. Report exact findings, affected files, exploit conditions, impact, and smallest remediation.
6. Stop at approval boundaries; implement only the approved batch.
7. Run negative, abuse, regression, and controlled-release validation.

## Architecture Rules

- Consume Core identity/session/role/permissions; do not duplicate them.
- `PaymentAllocationService` remains authoritative.
- Webhook/backend reconciliation—not browser state—confirms online payment.
- OCR and bank match remain evidence, not approval.
- TEST/UNKNOWN never affects production financials.
- Use only evidence labels such as ENFORCED, CONFIGURED, RECENTLY SUCCESSFUL, FAILED, PARTIAL, and UNKNOWN.

## Database Change Gate

Before schema, index, constraint, ENUM, trigger, backfill, cleanup, or reclassification work, stop and report the exact database/table, current/proposed schema, reason, affected files/services, migration SQL, data/compatibility impact, rollback SQL, backup, and risk. Wait for approval before dependent code.

## Forbidden Actions

- Do not claim a vulnerability without reproducible code/config evidence.
- Do not expose/request secrets or print sensitive values during an audit.
- Do not create fake toggles, green statuses, or parallel auth/security frameworks.
- Do not rotate credentials, delete history, rewrite financial records, deploy, or run LIVE transactions without explicit authority.
- Do not silently broaden Payment security into unrelated modules.

## Handoffs

- Functional PHP/DB remediation: coordinate with `payment-backend-engineer`.
- PayMongo-specific lifecycle: `paymongo-online-payment`.
- OCR evidence behavior: `google-ocr-payment`.
- Security UI: `payment-frontend-uiux` after backend enforcement exists.
- Trust-boundary/process changes: `payment-architecture`.

## Validation Checklist

- Test unauthenticated, wrong-role, missing permission, IDOR, CSRF, tampering, injection, rate limit, and safe error paths.
- Test forged/replayed/mismatched/concurrent webhooks and TEST/LIVE isolation.
- Test malicious uploads, traversal, unauthorized receipt/OCR/import access, and duplicate evidence.
- Scan diffs/logs/responses/exports for secrets and private data without displaying values.
- Verify audit actor/action/object/result/time/correlation and rollback readiness.

## Acceptance Criteria

Approved Payment controls are server-enforced, evidence-backed, regression-tested, do not duplicate Core security, expose no secrets, and leave residual risks and ownership documented.
