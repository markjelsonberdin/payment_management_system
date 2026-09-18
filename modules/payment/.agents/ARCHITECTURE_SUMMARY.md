# PAYMENT MANAGEMENT SYSTEM DOCUMENTATION

Bestlink College of the Philippines — repository-based baseline, September 16, 2026. **Implemented** means code/schema evidence exists; it does not prove a successful production test. **Partial**, **Requires Integration**, **Planned**, and **Unknown** identify separate states. No application or database change is authorized by this document.

## 1. Introduction

SMS2 Payment Management records assessed fees, student obligations, walk-in and PayMongo payments, allocation to billing items, concerns, receipt evidence, discounts, and collection reports. It serves authorized Finance/Accounting/Cashier personnel and students. Centralized records improve traceability and reduce duplicate financial logic. PayMongo supplies external payment events; Google Vision extracts receipt text but cannot approve a payment.

## 2. System Background

The PHP modular monolith separates shared SMS2 identity/session/permissions from Payment-owned financial data. Payment pages, APIs, services, and schema reside under `modules/payment/`; student payment UI is under `modules/student-portal/`. A separate Payment MariaDB connection is configured through `PAYMENT_DB_*` environment variables. The repository is evidence for design, not proof of the deployed schema or provider availability.

## 3. General Objective

Provide controlled, auditable recording and allocation of student payments with current billing balances and usable operational reporting.

## 4. Specific Objectives

Maintain fees and itemized billing; validate and allocate walk-in collections; create PayMongo checkout/QR attempts and verify signed events; isolate TEST from official LIVE collections; support concern submission, Accounting OCR review and optional AUB reconciliation; present balances, histories, and analytics; and establish authoritative student and enrollment integration contracts.

## 5. Scope of the Payment Management System

Payment owns fees, billing, payments, allocations, concerns, OCR results, imported statement evidence, gateway settings, discounts, and derived reporting. It consumes—not replaces—Core authentication and student identity. Enrollment assessment synchronization is not implemented. Card/wallet availability depends on PayMongo capability; LIVE currently permits QR Ph only in `PaymentValidationService`.

## 6. Users and Responsibilities

| Actor | Responsibility | Boundary |
|---|---|---|
| Student | View own billing/history; initiate, resume/cancel eligible attempts; submit concern | Cannot verify/allocate or read others' records |
| Cashier/Accounting | Generate billing, collect walk-in, review concerns, scan OCR, import AUB evidence | Human approval remains distinct from OCR/bank match |
| Finance/Admin | Fee and gateway settings; transaction history/analytics as permitted | Permissioned by SMS2 Core |
| SMS2 Core | Login, session, global role/permission, CSRF, activity logging | Does not own Payment financial records |
| PayMongo | Payment resources and signed events | Backend validation required |
| Google Vision/AUB | OCR text / statement evidence | Neither is payment authority |

## 7. Major Modules and Features

| Feature | Input -> process -> output | Dependency | Status |
|---|---|---|---|
| Fee setup | Fee/category data -> configuration -> active catalogue | Payment DB, Core permission | Implemented |
| Billing | Student, term, fee IDs -> `BillingService` -> billing/items | Student cache, fees | Implemented; Enrollment feed absent |
| Walk-in | Billing, target, cash -> validated collection/allocation -> receipt/balance | `PaymentAllocationService` | Implemented |
| Online/QR | Billing, channel, amount -> PayMongo resource -> attempt/event | PayMongo, webhook, secrets | Implemented; channel-dependent |
| Discounts | Billing, award/value -> `ScholarshipDiscountService` -> adjusted balance | Payment DB | Implemented locally; authority unconfirmed |
| Concerns/OCR | Claim/receipt -> Accounting scan/review -> evidence/decision | Vision, receipt storage | Implemented; runtime depends on Google billing |
| Bank reconciliation | AUB CSV/OCR result -> import/match -> evidence | Actual AUB format | Partial |
| History/analytics | Payment records -> official-scope queries -> views/CSV | `PaymentReportingScope` | Partial verification |

## 8. Overall Payment Architecture

```text
SMS2 Core auth/RBAC/CSRF ----> Payment pages and APIs <---- Student Portal
Registrar/Core student source -> local students reference cache
Enrollment assessment --------> [contract required; not implemented]
PayMongo <--------------------> creation API / signed webhook
Google Vision <--------------- Accounting OCR request
AUB CSV ----------------------> bank import/reconciliation
Payment DB: payments -> PaymentAllocationService -> allocations -> billing_items -> billing
                                               -> history / analytics / portal
```

This is one modular application, not independently deployed microservices. Browser, provider, file-upload, department, and database boundaries require explicit validation.

## 9. Walk-in Payment Process

### 9.1 Purpose

Record an authorized physical collection against an existing billing.

### 9.2 Actors

Student, Cashier/Accounting, Collection Portal, Payment DB, allocation service, Core audit.

### 9.3 Preconditions

Authenticated operator with `payment.collection`; valid CSRF; student, billing, eligible balance, amount, and cash received.

### 9.4 Process Flow

Operator retrieves billing, selects target/context, enters amount and cash. The server verifies CSRF, starts a transaction, locks billing with `FOR UPDATE`, validates amount/context, inserts a Verified walk-in payment, invokes `PaymentAllocationService`, commits, and logs activity. Allocation locks eligible items, inserts `payment_allocations`, and refreshes the billing summary; database triggers in the schema maintain item paid/remaining amounts.

### 9.5 Business Rules

`GENERAL_PRIORITY` follows category priority across unpaid items; `ENROLLMENT_PRIORITY` selects Enrollment/Enrollment Assessment items **excluding Tuition category 1**; `SPECIFIC_ITEM` targets one item; `CATEGORY_PRIORITY` targets a category. Over-allocation and empty eligible scopes fail. The payment/item pair is unique.

### 9.6 Security Controls

Page permission, CSRF, prepared SQL, transaction/locks, server-side checks, unique allocation pair, and selected audit call are implemented. Full endpoint/role negative tests remain necessary.

### 9.7 Outputs

Verified payment, reference/receipt, allocation rows, updated billing/items, ledger/history, collection report, and activity event—or rollback/error.

## 10. Online Payment Using PayMongo

### 10.1 Purpose

Create an online attempt without treating browser success as financial confirmation.

### 10.2 Actors

Student, Portal, Payment API/DB, PayMongo, signed webhook, allocation service.

### 10.3 Payment Channels

Code maps QR Ph, GCash, Maya/PayMaya, and card. `PaymentChannelService` checks availability; LIVE validation currently allows QR Ph only. Configuration does not prove merchant entitlement.

### 10.4 Process Flow

The authenticated, CSRF-checked, rate-limited API resolves student ownership, validates billing/context/amount/channel, computes fee and checkout total, creates a PayMongo checkout session or QR intent/method, and stores a Pending attempt and environment. PayMongo sends a signed event. The webhook maps and validates it, locks the payment, records idempotency, marks the eligible event Verified, and allocates **only explicit LIVE** attempts. TEST and unknown attempts remain non-official audit/reconciliation records.

### 10.5 QRPh Lifecycle

`create-qr-payment.php` sets a **10-minute** server-stored `expires_at`. Display/download does not create another attempt. Existing active target blocks duplication. Status polling reconciles expiry; cancellation locks and conditionally changes Pending. Valid QR resume retrieves the existing intent/QR and does not reset expiry. Non-QR resume has a separate session-replacement branch needing further state/race review.

### 10.6 Webhook Processing

`api/paymongo/webhook.php` uses `PayMongoWebhookSecurityService`, validates signature, provider mapping, amount/currency/environment/state, and event uniqueness, then performs the payment transition/eligible allocation in a DB transaction. Authentic late paid events can be reconciled. Paid-after-cancellation policy and the legacy `api/payment-webhook.php` require audit.

### 10.7 Test vs Live Environment

| Stored environment | Financial treatment |
|---|---|
| LIVE | Validated paid event can allocate and enter official Verified reporting |
| TEST | Technical Verified record only; no production allocation |
| UNKNOWN/null | Retain for manual classification; exclude from official totals |

### 10.8 Security Controls

Primary creation endpoints use authentication, CSRF, ownership, throttling, and server-side validation. Primary webhook uses signature/idempotency/locking. Legacy routes and historical records prevent a claim of complete coverage.

### 10.9 Outputs

Provider resource, pending/terminal attempt, environment/expiry, provider event, and—only for eligible LIVE success—financial allocation and official reporting.

## 11. Google OCR and Payment Concern

### 11.1 Purpose

Provide evidence to Accounting for a claimed prior transaction, separate from PayMongo's automatic payment confirmation.

### 11.2 Actors

Student, Portal, Accounting, `GoogleOCRService`, Vision, bank reconciliation, concern service, DB.

### 11.3 Receipt Submission

Student saves a concern and receipt through `student-concern-portal.php`. Client-supplied OCR fields and student-side automatic OCR were removed. Receipt path is stored with the concern.

### 11.4 OCR Extraction

Accounting calls `api/accounting/ocr-scan-concern.php`, which reads the saved receipt and asks Vision document-text detection. The parser attempts amount, reference, date, time, and bank/channel; statuses include COMPLETE, PARTIAL, AMBIGUOUS, and no-text/failure equivalents. Protected environment credentials are required; runtime service readiness remains unverified.

### 11.5 PMS Validation

`PaymentConcernVerificationService` compares claim and extracted evidence; bank matching can supplement it. Incomplete OCR does not itself reject the concern. The current one-row-per-concern OCR constraint limits immutable rescan history.

### 11.6 Accounting Review

Accounting checks the original receipt, OCR and bank evidence and makes an explicit approve/reject decision. `PaymentConcernService` locks the concern and uses the established payment/allocation path where an official payment is created.

### 11.7 Reconciliation

`BankReconciliationService` imports a simplified AUB CSV, checks file hash and application-level duplicate rows, then matches OCR reference, amount, and date. A match is evidence, never automatic approval.

### 11.8 Security Controls

OCR has authentication, CSRF, permission/object checks, session throttle, protected credentials, and selected audit logging. Receipt viewer uses concern ID and canonical path containment. Public-tree receipt storage and browser-declared CSV MIME remain partial controls.

### 11.9 Outputs

Concern, saved receipt, extracted evidence, optional bank match, Accounting decision, and approved official payment where applicable.

## 12. Comparison of Payment Processes

| Aspect | Walk-in | PayMongo | OCR/Concern |
|---|---|---|---|
| Initiator/source | Cashier; cash | Student; provider payment | Student claim; prior payment evidence |
| Verification/authority | Cashier plus backend checks | Validated signed webhook | Accounting decision after evidence review |
| External technology | None for cash | PayMongo | Vision; optionally AUB CSV |
| Human review | Cashier | Normally not for provider confirmation | Required |
| Allocation | Shared service | Shared service only for eligible LIVE | Shared service after approval |
| Receipt/reference | Generated payment receipt/reference | Provider/internal identifiers | Submitted receipt and claimed reference |
| DB update | Verified payment and allocation | Pending -> verified/terminal; LIVE allocation | Concern/OCR/bank evidence; approved payment |
| Audit/failure | Activity; rollback | Event/idempotency; expiry/cancel/failure | Review state; recoverable OCR failure |
| Controls | Permission/CSRF/locks | Ownership/CSRF/signature/environment | Permission/receipt access/no auto-approval |

## 13. Shared Financial Processing Architecture

```text
Walk-in Verified --+
LIVE webhook paid --+--> PaymentAllocationService --> payment_allocations
Approved concern ---+                                --> billing_items --> billing
                                                     --> history/analytics/portal
```

The three application paths invoke the shared service under their respective authority. This avoids divergent allocation rules and supports consistent balances, constraints, and reporting.

## 14. Payment Security Architecture

Payment consumes Core identity and implements payment-specific permission, object ownership, CSRF, validation, locks, webhook verification, evidence access, and audit. Principal flows have controls; uniform endpoint coverage, legacy route retirement, upload hardening, secret-history cleanup, retention/encryption, and complete event audit are **partial/planned**. No claim is made that UI-hidden functions are protected. Global login/2FA/session policy remains Core's responsibility.

## 15. Department and Module Integrations

### 15.1 Registrar

Required for a canonical student ID/number, name, program/course, year, section where relevant, and status. `RegistrarStudentClient` first consults Payment's `students` cache, then a configured Registrar URL or fallback User Management student API on a miss. The latter returns placeholder academic fields. This is a **partial adapter**, not proof of completed Registrar ownership or secure service contract. Payment should cache references, not become the academic identity authority; any return of financial clearance needs a confirmed business contract.

### 15.2 Enrollment

Required to automate approved assessment-to-billing: enrollment/assessment ID, student ID, academic year, semester, program/year, enrollment state, applicable fees, revision and cancellation rules. No concrete Enrollment-to-Payment API/adapter was found. Payment can generate billing manually, but automated synchronization is **Requires Integration**. A minimal cleared/not-cleared response should be added only if Enrollment needs it.

### 15.3 Other Required Integrations

SMS2 Core and Student Portal are P0. Accounting/Finance is an operational actor inside Payment, not necessarily a separate system. External scholarship ownership is unknown; Payment currently maintains discount records. MIS governs deployment and permissions but is not a financial owner. PayMongo, Vision, and AUB are capability-specific external dependencies.

## 16. Integration Priority Matrix

| Module | Priority/reason | Provides to Payment | Receives from Payment | Direction/method/status |
|---|---|---|---|---|
| Core/User Management | P0; secure identity | User/role/session/CSRF | Audit actions | Internal helpers; implemented, contract needs confirmation |
| Registrar | P0; correct student association | Canonical student profile | Optional clearance | Authenticated versioned JSON + local cache proposed; partial fallback |
| Enrollment | P1; automated assessment | Term/status/approved assessment | Optional payment clearance | Idempotent API/event proposed; absent |
| Student Portal | P0; student channel | Authenticated actions/claims | Billing/history/status | Internal pages/APIs; implemented |
| Accounting/Finance | P0; decision/collection | Human collection/review | Evidence/reports | Permissioned UI; implemented |
| Scholarship owner | Unknown/P1 if separate | Award approval | Application impact | Contract pending ownership decision |
| PayMongo | P0 online | Signed event/resource | Creation request | HTTPS REST/webhook; implemented |
| Google Vision/AUB | P1 respective features | Text/statement | Receipt request/none | Client library/CSV; partial operational readiness |

## 17. Data Ownership Matrix

| Data | Authority | Payment stores/reads/writes | Note |
|---|---|---|---|
| User identity/roles | SMS2 Core | Reference ID/read/no authority write | Shared security |
| Student profile | Registrar/Core owner to confirm | Cache/read/cache-sync | Not second registry |
| Enrollment, AY, semester | Enrollment/institution to confirm | Billing snapshot/read/manual entry | Integration absent |
| Fee, billing, billing item | Payment/Finance | Yes/yes/yes | Financial obligation |
| Scholarship/discount | Payment currently; external owner unknown | Yes/yes/yes | Confirm award authority |
| Payment/allocation/collection | Payment | Yes/yes/yes | Financial source of truth |
| Receipt/concern/OCR | Payment | Yes/yes/yes | Sensitive evidence |
| Bank statement | Bank origin; Payment imported copy | Yes/yes/import | Match is not approval |
| Audit | Core and Payment event tables | Partial/read/selected writes | Coverage incomplete |

## 18. API and System Integration Architecture

| Source -> destination | Data/purpose | Method/security | Trigger/response/failure | Status |
|---|---|---|---|---|
| Payment -> student source | Student number/profile | HTTP JSON GET; service auth unproven | Cache miss; timeout/null | Partial |
| Enrollment -> Payment | Assessment/term | Authenticated versioned API proposed | Approved assessment; idempotent ack/retry needed | Not implemented |
| Portal -> Payment | Billing/checkout/QR/status/concern | PHP/JSON; action-specific auth/CSRF | User action; result/error | Implemented/partial |
| Payment -> PayMongo | Checkout/intent/query | HTTPS REST, server secret | Initiation; provider resource/error | Implemented |
| PayMongo -> webhook | Paid/lifecycle event | Signed POST, idempotency | Event; safe acknowledgement/retry | Implemented primary route |
| Payment -> Vision | Receipt bytes/text | Google client, service account | Accounting scan; result/manual fallback | Implemented code, readiness unknown |
| Accounting -> AUB import | Statement rows | Multipart POST, permission/CSRF | Manual CSV; result/rollback | Partial |

Registrar and Enrollment URLs, payload versions, service credentials, retry rules, and response schemas need Lead Programmer agreement; none are invented here.

## 19. Database Architecture

The repository dump `modules/payment/database/payment_db.sql` defines 15 principal tables: `students`, `fee_categories`, `fees`, `billing`, `billing_items`, `payments`, `payment_allocations`, `paymongo_transactions`, `payment_concerns`, `ocr_results`, `bank_statements`, `bank_statement_rows`, `scholarships`, `payment_gateway_settings`, `payment_settings_audit`. Student 1:M Billing; Billing 1:M Items/Payments; Payment M:N Item through Allocation; Student 1:M Concern; Concern 0..1 OCR row; Bank Statement 1:M Rows. Deployed schema must be checked separately; no database write was performed.

## 20. BPMN Specifications

### 20.1 Walk-in

Pool SMS2; lanes Student, Cashier, PMS, DB. Start: cash presentation. User tasks: locate billing/choose target/enter amount. Service tasks: authorize, lock, validate, create, allocate, audit. Gateways: eligible user/billing/amount and allocation success. Ends: committed payment or rollback.

### 20.2 Online PayMongo

Pools SMS2 and PayMongo; lanes Student, Portal, PMS, webhook, DB. Start: online choice. Tasks: validate, create Pending, provider resource, student interaction, signed callback, duplicate/environment gateway, LIVE allocation. Message flows: creation and signed event. Ends: Pending, Verified official/audit-only, Failed, Cancelled, Expired, or reconciliation.

### 20.3 Google OCR

Pools SMS2, Vision, bank evidence; lanes Student, Portal, Accounting, OCR/Concern, DB. Start: concern submission. Tasks: store receipt, authorize scan, extract/parse, compare bank, human approve/reject, optionally allocate. Gateways: valid file, available OCR, match/evidence, human decision. Ends: reviewed payment, rejection, pending review, or recoverable OCR failure.

## 21. ERD Specification

| Existing entity (PK) | FK/relationship | Purpose |
|---|---|---|
| students (`student_id`) | External `user_id` reference; parent of billing/payment/concern | Student cache |
| fee_categories (`category_id`), fees (`fee_id`) | `fees.category_id` | Fee priority/master |
| billing (`billing_id`), billing_items (`billing_item_id`) | `student_id`; item `billing_id`,`fee_id` | Term obligation/items |
| payments (`payment_id`) | `student_id`,`billing_id`, optional `billing_item_id` | Attempt/verified payment |
| payment_allocations (`allocation_id`) | `payment_id`,`billing_item_id`; unique pair | Item distribution |
| paymongo_transactions (`paymongo_transaction_id`) | Optional `payment_id` | Provider/event evidence |
| payment_concerns (`concern_id`), ocr_results (`ocr_result_id`) | `student_id`, optional `payment_id`; OCR `concern_id` unique | Claim and current scan |
| bank_statements (`id`), bank_statement_rows (`id`) | Row `statement_id` | Batch/row evidence |
| scholarships (`scholarship_id`) | `student_id`, optional `billing_id` | Award/discount |
| payment_gateway_settings (`setting_key`), payment_settings_audit (`audit_id`) | Settings key in audit data | Config and change record |

No recommended integration/audit table is claimed to exist.

## 22. Sequence Diagram Specifications

Walk-in: `Cashier -> Portal -> DB lock -> payment -> AllocationService -> items/billing -> audit -> response`. Online: `Student -> Portal -> Payment API -> PayMongo`; independently `PayMongo -> signed webhook -> DB lock/idempotency -> LIVE allocation -> portal reporting`. OCR: `Student -> concern/receipt`; `Accounting -> OCR API -> Vision -> result -> bank evidence -> Accounting decision -> approved payment/allocation`.

## 23. Technology Stack and External Services

| Technology | Use/problem | Security consideration |
|---|---|---|
| PHP 8.1+, PDO/MariaDB | Server logic, durable financial transactions | Validation, locks, prepared SQL, backups |
| HTML/CSS/JS/Bootstrap | Portal and staff UI | Client display is untrusted |
| Composer/Google client | Vision dependency | Lockfile and credential protection |
| PayMongo REST/QR Ph | Online acceptance | Keys, HTTPS, signed webhook, TEST/LIVE |
| Google Vision | Receipt text detection | Billing/quota and evidence boundary |
| HostForge | Deployment and protected variables | Reproducible build, secrets, health |
| AUB CSV | Bank evidence | File/row validation and authorization |

## 24. Security Controls Matrix

| Control | Status | Evidence/remaining gap |
|---|---|---|
| Core auth and principal page permissions | Partial | Helpers used; full route matrix pending |
| CSRF, ownership, prepared SQL | Partial | Present in primary paths; legacy/settings inventory pending |
| Transactions/locks/unique allocation | Implemented primary paths | Concurrency regression needed |
| Checkout/QR throttling | Partial | Selected endpoints; OCR session throttle |
| Webhook HMAC/idempotency/amount/currency | Implemented primary route | Legacy route and race audit pending |
| TEST/LIVE financial isolation | Partial verification | LIVE-only primary allocation; history reconciliation needed |
| Receipt/OCR access | Partial | Concern-ID viewer/scan permission; public-tree storage |
| Bank import | Partial | Permission/CSRF/hash; browser MIME and CSV assumptions |
| Secret configuration | Partial | Payment real env ignored; historical Git secret remains |
| Audit, encryption, retention | Partial/planned | Selected logs; full model requires approval |

## 25. Implementation Status

| Component | Classification | Qualification |
|---|---|---|
| Walk-in, billing, allocation, concern | Implemented | Runtime regression still required |
| QR, checkout, primary signed webhook | Implemented | LIVE merchant capability and deployed behavior external |
| History/analytics, TEST/LIVE historical data | Partial | Official-scope predicate exists; data audit pending |
| OCR | Implemented code; external dependency | Vision credential/billing readiness unknown |
| Bank reconciliation | Partial | Actual AUB layout unconfirmed |
| Registrar | Partial | User Management fallback, not Registrar contract |
| Enrollment | Requires Integration | No confirmed adapter |
| Payment security/audit | Partial | Cycle B deferred |

## 26. Current Limitations and Integration Dependencies

Registrar source ownership and service authentication are unresolved. Enrollment assessment transport is absent. AUB CSV parser assumes five columns. OCR's unique concern row does not preserve immutable rescan records. Receipt storage is under the application tree. Legacy payment routes need inventory. Historical TEST/UNKNOWN allocation requires read-only reconciliation. HostForge builds have timed out compiling GD, an operational blocker separate from financial architecture.

## 27. Recommended Integration Sequence

First formalize Core identity/permission IDs; second establish Registrar canonical student contract; third agree Enrollment assessment IDs, revision and clearance semantics; fourth verify reporting and historical environment classification; fifth validate PayMongo/Vision/AUB operation; sixth conduct a separately approved Payment security cycle. Do not require Enrollment to own Payment balances or Registrar to own transactions.

## 28. Conclusion

Payment has substantive walk-in, online, concern, and reporting code centered on one allocation service. A signed backend PayMongo event authorizes online completion; OCR/bank evidence requires Accounting judgment. Registrar is P0 for reliable student association. Enrollment is P1 for complete automated assessment, but manual billing can presently operate without its adapter. Formal contracts and production verification remain necessary before asserting complete integration.

# DOCUMENTATION FINDINGS

## A. Confirmed Existing Architecture

PHP modular monolith; Core security; separate Payment DB; allocation service; PayMongo primary webhook; student-facing Portal; Accounting concern review.

## B. Implemented Processes

Fee/billing setup, walk-in collection, allocation, QR/checkout creation, primary webhook, concern submission/review, OCR service, reporting interfaces.

## C. Partially Implemented Processes

Registrar cache/fallback, TEST/LIVE historical reconciliation, AUB import hardening, immutable OCR rescans, complete audit and endpoint security.

## D. Planned Processes

Enrollment contract, separately approved Cycle B hardening, retention/encryption design, complete monitoring.

## E. Missing Integration Dependencies

Authoritative Registrar endpoint and schema, Enrollment assessment transport, actual AUB CSV specification, scholarship decision owner.

## F. Registrar Integration Requirements

Stable student ID/number, name, program, year, section/status, update timestamp, authentication, versioning, stale-cache/deactivation policy; only required financial-clearance data returned.

## G. Enrollment Integration Requirements

Assessment/enrollment ID, canonical student ID, term, status, approved fee/context, revision/cancellation and idempotency rules; optional clearance response only if justified.

## H. Other Required Department Integrations

Core and Student Portal are P0. Accounting/Finance is an operational role. External scholarship source remains unknown. MIS is operational support, not financial authority.

## I. Payment Security Currently Implemented

Core-auth consumption, selected permissions/CSRF/ownership/throttles, transaction locks, unique allocations, signed/idempotent webhook, LIVE-only primary allocation, protected environment usage.

## J. Payment Security Still Needed

Full route/role matrix, legacy cleanup, file validation/storage hardening, comprehensive audit, historical-secret rotation/history cleanup under separate approval, retention/encryption policy.

## K. Documentation vs Code Discrepancies

The old summary incorrectly says Enrollment Priority pays Tuition first (code excludes it), QR resume regenerates a QR (current QR path reuses it), and OCR belongs to cashier collection (current scan is Accounting concern review). Rules still reference 30-minute QR tests although code uses 10 minutes. Registrar class naming implies a full integration though fallback is User Management with placeholder academics.

## L. Database/Schema Concerns

`ocr_results.concern_id` unique constraint limits rescan history; runtime limiter storage differs from principal dump; `payment_date` is reused in cancellation; bank-row unique migration and deployed schema need verification; Enrollment identifiers absent. **Recommendation only:** assess separate OCR attempt history and integration idempotency fields after current-schema inventory, with exact migration/rollback and explicit approval. No SQL was run.

## M. Recommended Integration Order

Core IDs/permissions -> Registrar identity -> Enrollment assessment -> reporting reconciliation -> provider/bank validation -> separately approved security cycle.

## N. Questions That Must Be Confirmed With Client / Lead Programmer

Who owns canonical student data and academic term? What triggers/revises billing? Does Enrollment need clearance details? Who approves scholarships? What is the actual AUB format? What is the paid-after-cancel rule? Which historical online rows are TEST/LIVE/UNKNOWN? What retention applies to receipts? Which legacy endpoints are active? What service authentication will Registrar/Enrollment use?
