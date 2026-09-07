---
trigger: always_on
---

# Payment Management System — Agent Rules

## 1. SOURCE OF TRUTH
- Existing PMS code, database/schema, services, security controls, and finalized BPMN are authoritative.
- Inspect actual files, callers, dependencies, routes, and schema before editing. Never guess.
- Never blindly copy, overwrite, delete, rename, or replace working backend files.
- Kenneth's code is a UI/research candidate unless actual code proves compatibility. Extract 
UI only when production backend must remain.
- Make the smallest safe change. Reuse existing helpers/services; do not create parallel logic.
- `PaymentAllocationService` is the single allocation authority. Do not rewrite or duplicate it unless explicitly required and proven necessary.
- Do not change finalized BPMN decisions unless the user explicitly asks. Implementation must conform to it.
- Never expose or request passwords, API keys, Google private keys, PayMongo secrets, or tokens.

## 2. ARCHITECTURE
- Modular monolith with clear boundaries.
- Global security remains authoritative: authentication, sessions, RBAC, CSRF, audit logging.
- `PaymentSecurityService` is a payment-specific coordinator, not a replacement for global security.
- Responsibilities:
  - `PaymentSecurityService`: payment object access, context/state checks, pending/duplicate protection, coordination with existing security helpers.
  - `GoogleOCRService`: Google Vision integration, OCR extraction/normalization, OCR results. Never approve/post/allocate payments.
  - `PaymentConcernVerificationService`: PMS business validation of concern/OCR data.
  - `BankReconciliationService`: bank import, normalization, duplicate detection, reconciliation.
  - `PaymentConcernService`: concern lifecycle and approved transition to official payment.
  - `PaymentAllocationService`: authoritative payment distribution.
- Avoid god classes, duplicate validators, duplicate webhook security, circular dependencies, and multiple payment-processing paths.

## 3. FINANCIAL DATA INTEGRITY
- Backend is authoritative for student/billing ownership, amount, allocation context, target, and payment state.
- Never trust frontend hidden fields, IDs, amounts, URLs, or priority/context flags without server validation.
- Protect state transitions; never process invalid/cancelled/completed payments.
- Use DB transactions for atomic financial operations and `SELECT ... FOR UPDATE` on an appropriate stable row/scope when concurrency requires it.
- Do not hold DB locks open unnecessarily during external API calls.
- Where practical, back duplicate checks with DB unique constraints/indexes.
- Preserve historical/audit records; do not delete financial history to fix errors.

## 4. WALK-IN PAYMENT
- Contexts may include Enrollment, Post-Enrollment, and General Full Payment, subject to actual billing/configuration.
- Frontend may propose a context; server validates and determines the allowed context.
- Validate student/billing relationship, unpaid items, amount, state, and context before creating payment.
- Cashier must not directly manipulate allocation from the client.
- Delegate distribution to `PaymentAllocationService`.
- Prevent duplicate payments/double allocation with database-safe concurrency controls.
- Preserve transaction order, billing/collection history, OR/reference traceability, and audit logging.

## 5. QR PH / PAYMONGO
- Use transaction-specific/dynamic QR where applicable.
- Each payment attempt has `created_at`, `expires_at`, and status. QR validity is 30 minutes unless business rules change.
- Expiry belongs to the specific payment attempt, not the student/billing account globally.
- Download/Open/Display QR must not create another payment attempt.
- Do not rely on browser timers or session-only duplicate checks.
- Do not unnecessarily hold DB locks while calling PayMongo.
- Webhook order: signature → idempotency → identify internal intent/attempt → validate ownership/context → amount/currency → state transition → expiry/late-payment rule → reconcile/process.
- Use existing `PayMongoWebhookSecurityService` when present; do not duplicate signature logic.
- A validated `payment.paid` webhook is the external completion authority; browser success/polling is never proof of payment.
- Late legitimate PayMongo payments may be reconciled as paid after expiry if the webhook is authentic and all PMS consistency checks pass.

## 6. GOOGLE OCR
- OCR is evidence/extraction, not financial authority.
- Intended flow: student submits concern + receipt → secure storage → authorized reviewer triggers OCR → Google Vision → OCR result → PMS validation → bank reconciliation when available → Accounting decision → official payment.
- Do not make student submission equal OCR approval or payment approval.
- Inspect the actual authoritative upload/scan endpoints before changing them.
- Required fields: Reference Number, Date & Time, Amount, Bank/Payment Channel.
- Do not assume fixed reference length, take the first number as amount, or fabricate missing dates/times.
- Normalize only when source data supports reliable conversion.
- OCR statuses may be `COMPLETE`, `PARTIAL`, `AMBIGUOUS`, `NO_TEXT`, `FAILED`.
- OCR failure/partial/ambiguity is not automatically fraud or rejection.
- Verification can include: reference, amount, date/time, duplicate reference, student identity, and payment status when official transaction data exists.
- Preserve OCR rescan history with attempt number/result ID and who/when scanned.
- Rate-limit OCR before calling Google Vision.
- Load Google credentials from protected configuration/secret storage; never commit or log them.

## 7. RECEIPT UPLOAD / VIEWING
- Validate upload error, file size, detected MIME (`finfo` or equivalent), allowed type/extension, and safe filename.
- Never rely only on client MIME values.
- Use randomized server-side filenames and prevent executable uploads.
- Prefer storage outside the public web root. If public-tree storage is unavoidable, web-server deny rules are defense-in-depth, not the sole control.
- Never accept an arbitrary filesystem path for receipt access. Prefer a stable object ID such as `concern_id`.
- Secure view/download: authenticate → authorize object → resolve stored path server-side → prevent path traversal → stream file.

## 8. AUB / BANK RECONCILIATION
- Manual AUB CSV import is the baseline unless a real AUB API is explicitly integrated.
- Import flow: permission → file validation → SHA-256 → import batch → normalize → duplicate checks → store records → audit.
- Track source bank, original filename, file hash, batch ID, uploader, timestamp, row count, and status where supported.
- File hash catches identical files; it does not replace transaction-level duplicate detection.
- Row identity should use the strongest available bank identifier. Otherwise use a bank-scoped combination such as source bank + reference + date/time + amount and other reliable fields.
- Use application checks plus DB uniqueness where practical.
- Preserve reconciliation outcomes such as `MATCHED`, `PARTIAL_MATCH`, `AMOUNT_MISMATCH`, `REFERENCE_MISMATCH`, `DATE_MISMATCH`, `NOT_FOUND`, or project-approved equivalents.
- Bank match is evidence, not automatic Accounting approval.
- Bank import must not directly create/allocate an official payment unless the approved workflow explicitly does so.

## 9. PAYMENT CONCERN / APPROVAL
- Keep student-claimed data, OCR data, official bank data, and Accounting-verified data logically distinct.
- Financial approval is an Accounting decision under the locked process; do not infer it from OCR or bank match alone.
- If an official payment already exists, reconcile it rather than blindly creating another.
- If approved and no official payment exists, use established payment services, then the existing allocation engine.
- Preserve evidence and audit history for rejection/correction/review states.

## 10. AUTHORIZATION
- Hidden UI is not security. Every sensitive page/API must enforce server-side authorization.
- Use granular permissions for sensitive actions, e.g. `payment.ocr.scan` and `payment.bank_reconciliation.import`.
- Use object-level authorization for billing, payment, concern, receipt, and reconciliation records.
- Student: own records only. Cashier: authorized collection scope. Accounting: authorized verification/reconciliation/approval scope. Admin: permission-based administrative scope.
- Reuse existing auth/RBAC/CSRF mechanisms instead of duplicating them.

## 11. AUDIT
- Log financially significant actions: creation, approval/rejection, cancellation, reconciliation, OCR scan/rescan, bank import, and security-denied attempts where appropriate.
- Include actor, action, target/object, timestamp, result, and relevant IDs.
- Do not log secrets, full private documents, or unnecessary sensitive payloads.

## 12. KENNETH UI MERGE
- Kenneth's UI can be transplanted; existing payment backend remains authoritative.
- Extract HTML/layout/CSS patterns, not production PHP architecture, unless compatibility is proven.
- Check global CSS selectors and shared includes for payment-page conflicts.
- Keep role-aware sidebar visibility, but enforce authorization in backend.
- Do not import unrelated research/module files into Payment Management.
- For duplicate pages, compare dependencies/architecture first; never blindly merge both.

## 13. DEPLOYMENT / CONFIGURATION
- Separate development and production configuration.
- Never commit Google service-account keys or PayMongo secrets to Git.
- Use protected environment/secret storage and least-privilege credentials.
- Disable verbose production errors; sanitize external/API/database errors.
- Use HTTPS in production. Tunnels such as ngrok are temporary testing/webhook tools, not production security.
- Before release verify webhook reachability, secrets/configuration, DB migrations, permissions, and private receipt storage.
- Back up code/database before migrations or major security refactors.

## 14. TESTING
- Test positive and negative paths, including direct API/URL access and unauthorized roles.
- QR: concurrent attempts, pending logic, 30-minute expiry, download/open behavior, failure/expiry, valid/invalid webhook, duplicate event, wrong amount, late valid webhook.
- Walk-in: all supported contexts, amount/context tampering, billing mismatch, duplicates, concurrency, and allocation correctness.
- OCR: clear/partial/ambiguous/no-text receipts, wrong amount/reference, duplicate reference, wrong student/billing, rescan history, unauthorized scan, rate limiting.
- AUB: same file twice, different file with overlapping row, duplicate transaction, malformed CSV, wrong headers, mismatches, not-found, unauthorized import.
- After changes, run affected-module regression and end-to-end payment-flow tests.

## 15. TROUBLESHOOTING
Reproduce → inspect request/response and safe server errors → trace endpoint/service/DB/external API → verify schema/config/permissions → fix the smallest root cause → regression-test. Never bypass security or rewrite unrelated modules to hide a symptom.

## 16. FINAL DECISION RULE
Before adding anything, verify it is required by the approved process, not already handled by an existing service/helper, materially improves security/integrity/compatibility/functionality, introduces no unnecessary duplicate logic/dependency, and can be isolated safely. Otherwise do not add it.

## NON-NEGOTIABLES
- Existing PMS payment/security architecture is the source of truth.
- Server-side authorization and validation are mandatory.
- Frontend values never control financial decisions by themselves.
- OCR never directly approves, posts, or allocates payment.
- AUB match never equals automatic approval.
- Validated PayMongo webhook, not browser UI, confirms online payment completion.
- `PaymentAllocationService` remains the single allocation authority.
- No blind file replacement or unnecessary dependencies.
- No secrets in source control or logs.

