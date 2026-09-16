---
name: paymongo-online-payment
description: Diagnose and implement SMS2 PayMongo online-payment flows including QRPh, GCash, Maya, cards, checkout sessions, payment intents, expiry, resume/cancel, fees, webhooks, reconciliation, and TEST/LIVE isolation. Use for provider-specific lifecycle work; never treat browser state as payment authority.
---

# PayMongo Online Payment

## Purpose

Own provider-specific analysis and implementation from payment creation through authoritative webhook verification, allocation, and Student Portal synchronization.

## Required Context

Before acting, read:

- [Repository agent guide](../../AGENTS.md)
- [Payment rules](../../rules/payment-system.md)
- [Cycle A plan](../../payment-module-implementation-plan.md)

Read the [Cycle B security plan](../../payment-module-security-implementation-plan.md) only for authorized security work. Inspect PayMongo config, services, creation/status/cancel/resume endpoints, webhook, database fields, and frontend caller before changing behavior.

## Lifecycle

```text
Payment request -> PMS validation -> Pending attempt -> PayMongo resource
  -> supported channel -> PayMongo event -> signed webhook
  -> idempotency and internal validation -> eligible Verified payment
  -> PaymentAllocationService -> billing/history/Student Portal
```

Expiry/cancellation/failure remain auditable states. An authentic late paid event requires the approved reconciliation policy.

## Responsibilities

- TEST/LIVE keys, metadata, isolation, and reporting eligibility
- QRPh/GCash/Maya/card availability and provider identifiers
- Checkout session, payment intent/method, QR rendering data, and authoritative expiry
- Pending duplicate protection, resume/cancel behavior, fees, and idempotency
- Webhook signature, event replay, amount/currency/context/environment validation
- Success/failure/expiry UI contracts and reconciliation

## Workflow

1. Reproduce and trace the exact attempt by internal and provider identifiers.
2. Confirm active environment without exposing credentials.
3. Trace create → persisted attempt → provider response → webhook → allocation.
4. Separate provider behavior, PMS state, and browser presentation.
5. Check database impact and invoke the database gate if needed.
6. Implement the smallest compatible fix and test duplicate/concurrent/late events.

## Critical Rules

- QR scan, redirect, polling, and success UI are not payment authority.
- Only a cryptographically validated backend event/reconciliation can confirm external payment completion.
- TEST/UNKNOWN never allocates production billing or enters official collections.
- Determine environment from authoritative stored/provider metadata, never amount, date, student, or reference format.
- Reuse `PayMongoWebhookSecurityService` and `PaymentAllocationService`.
- Use the server-stored `expires_at`; do not restart expiry on display/download/refresh.
- Existing rules conflict between 10- and 30-minute QR expiry references. Inspect configured code/data and report the conflict before changing duration.

## Forbidden Actions

- Do not mark Verified from client-controlled success.
- Do not regenerate a payment merely to download/open/display its QR.
- Do not silently ignore an authentic paid event after expiry/cancel.
- Do not expose secrets/raw provider payloads or run LIVE charges without explicit approval.
- Do not perform unapproved schema/backfill changes.

## Handoffs

- General PHP/DB work: `payment-backend-engineer`.
- UI rendering only: `payment-frontend-uiux`.
- Flow/diagram conflict: `payment-architecture`.
- Provider security defect: `payment-security` under Cycle B approval.

## Validation Checklist

- Creation persists complete owner, billing, amount, channel, environment, state, and expiry context.
- Signature, replay, amount, currency, environment, state, and idempotency tests pass.
- Duplicate/concurrent, refresh, multiple tabs, cancel/payment, and expiry/payment races are covered.
- LIVE allocates exactly once; TEST/UNKNOWN never changes production billing.
- Student, history, analytics, and provider records agree.

## Acceptance Criteria

The provider lifecycle is traceable, environment-safe, idempotent, authoritative on the backend, and consistent across billing and UI.
