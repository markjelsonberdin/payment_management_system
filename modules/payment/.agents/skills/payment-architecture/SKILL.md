---
name: payment-architecture
description: Analyze and document the actual SMS2 Payment Management architecture, processes, services, databases, APIs, and integrations. Use for BPMN, ERD, DFD, use cases, sequence/component/system diagrams, architecture decisions, and implementation-document reconciliation; never invent unimplemented components.
---

# Payment Architecture

## Purpose

Guard the Payment Management system's real boundaries and produce documentation that matches implementation.

## Required Context

Before acting, read:

- [Repository agent guide](../../AGENTS.md)
- [Payment rules](../../rules/payment-system.md)
- [Cycle A plan](../../payment-module-implementation-plan.md)
- [Cycle B security plan](../../payment-module-security-implementation-plan.md) when security boundaries are relevant

Then inspect the involved pages, endpoints, services, database definitions, and callers. Treat prose/reference folders as claims until code and schema confirm them.

## Scope

- Module, service, API, database, deployment, and external-integration boundaries
- Payment processes and business rules
- BPMN, ERD, DFD, use case, sequence, component, system, API-flow, service-flow, and integration diagrams
- Architecture discrepancy reports and decision records

## Authoritative Flow

Preserve unless repository evidence and explicit approval establish a change:

```text
Verified eligible payment
  -> PaymentAllocationService
  -> payment_allocations
  -> billing_items / billing
  -> history and ledger
  -> collection analytics
  -> Student Portal
```

Online completion comes from a validated PayMongo webhook. OCR and bank reconciliation provide evidence; Accounting approval authorizes concern-based official payment processing.

## Workflow

1. Define the question and diagram/document type.
2. Trace the real path end-to-end from UI/caller to database and external service.
3. Record ownership, trust boundaries, states, transactions, and failure paths.
4. Compare implementation with existing documentation.
5. Report discrepancies before proposing a change.
6. Produce a diagram whose nodes and arrows map to confirmed artifacts.

## Architecture Rules

- The repository is a modular monolith unless independently deployed services prove otherwise.
- REST endpoints and service classes are not automatically microservices.
- Do not duplicate `PaymentAllocationService` or authoritative balance calculations.
- Keep SMS2 Core identity/global security separate from Payment financial ownership.
- Diagram TEST/LIVE, webhook, OCR, reconciliation, and manual approval boundaries explicitly when relevant.

## Forbidden Actions

- Do not invent tables, endpoints, queues, services, events, or deployment topology.
- Do not silently rewrite code or documentation to resolve a disagreement.
- Do not convert the application to microservices merely to match capstone wording.
- Do not implement application changes during a documentation-only task.

## Handoffs

- Backend implementation: `payment-backend-engineer`.
- Interface implementation: `payment-frontend-uiux`.
- Provider lifecycle detail: `paymongo-online-payment` or `google-ocr-payment`.
- Threat/control architecture: `payment-security` after required approval.

## Validation Checklist

- Every component maps to a real file, table, external service, or explicitly marked proposal.
- Direction, state transitions, authority, and failure/reconciliation paths are correct.
- Documentation conflicts are listed with evidence and recommended resolution.
- No schema or architecture change is implied as already implemented.

## Acceptance Criteria

The artifact is traceable to current code/schema, distinguishes current versus proposed design, and preserves authoritative financial and security boundaries.
