---
name: google-ocr-payment
description: Diagnose and improve Google Cloud Vision OCR for SMS2 Payment Concerns, including secure receipt intake, extraction, normalization, duplicate detection, rescan states, validation evidence, Accounting review, and reconciliation handoff. Use for OCR-specific work; OCR never approves or allocates payments.
---

# Google OCR Payment

## Purpose

Extract reliable receipt evidence for Payment Concerns while keeping financial approval under PMS validation and authorized Accounting review.

## Required Context

Before acting, read:

- [Repository agent guide](../../AGENTS.md)
- [Payment rules](../../rules/payment-system.md)
- [Cycle A plan](../../payment-module-implementation-plan.md)

For approved security work, also read the [Cycle B security plan](../../payment-module-security-implementation-plan.md). Inspect the upload endpoint, receipt-access path, `GoogleOCRService`, concern verification/service, bank reconciliation, schema, and reviewer UI.

## Evidence Flow

```text
Student concern + receipt -> protected PMS storage -> authorized OCR request
  -> Google Cloud Vision -> extraction/normalization -> PMS validation
  -> reconciliation evidence -> Accounting approve/reject
  -> established official-payment path -> PaymentAllocationService -> audit
```

## Responsibilities

- Receipt intake contract and OCR invocation
- Reference, amount, transaction date/time, and bank/channel extraction
- Conservative normalization and confidence/evidence presentation
- COMPLETE, PARTIAL, AMBIGUOUS, NO_TEXT, and FAILED-equivalent outcomes
- Duplicate reference/receipt detection and rescan history
- Integration with Payment Concern validation, bank evidence, and reviewer decisions
- Safe provider errors and credential usage

## Workflow

1. Preserve the original receipt and reproduce the extraction result safely.
2. Separate raw provider output, normalized OCR fields, student claims, bank evidence, and Accounting values.
3. Identify whether failure is upload, provider, parsing, normalization, duplicate detection, or UI presentation.
4. Check callers/schema before changing result formats.
5. Implement conservative extraction rules; never fabricate missing values.
6. Test clear, partial, ambiguous, no-text, rotated, noisy, and duplicate examples.

## Architecture Rules

- OCR is extraction evidence, not financial authority.
- Do not assume fixed reference length, select the first number as amount, or invent missing timestamps.
- Partial/ambiguous/failure is not automatically fraud or rejection.
- Authorized Accounting review remains required.
- Approved payment uses existing Payment Concern and allocation services.

## Forbidden Actions

- Do not auto-create/verify/allocate payment from OCR output.
- Do not overwrite the original receipt or erase scan history.
- Do not log receipt contents, credentials, or unnecessary raw OCR payloads.
- Do not weaken upload/access controls to make OCR work.
- Do not make unapproved schema changes.

## Handoffs

- Upload/access security: `payment-security` after Cycle B approval.
- Schema/API/service work: `payment-backend-engineer` and database gate.
- Reviewer UI: `payment-frontend-uiux`.
- Process discrepancy: `payment-architecture`.

## Validation Checklist

- Extracted values remain traceable to source evidence.
- Outcome state accurately represents completeness/ambiguity.
- Duplicate/rescan behavior is deterministic and auditable.
- Unauthorized users cannot scan or view another student's receipt.
- OCR/provider failure leaves the concern recoverable for manual review.

## Acceptance Criteria

OCR improves review efficiency without becoming approval authority, losing evidence, exposing private data, or bypassing the official payment path.
