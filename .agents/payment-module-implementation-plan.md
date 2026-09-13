# SMS2 Payment Management Module — Implementation Plan

## Document Status

- **Scope:** Payment Management module lang
- **Status:** Cycle A implementation in progress; dedicated Cycle B security implementation remains deferred
- **Database authorization:** Wala pa; hindi automatic permission ang document na ito para mag-run ng SQL o migration
- **Financial source of truth:** Payment Database
- **Authentication/RBAC source of truth:** SMS2 Core at lead programmer
- **Online-payment completion authority:** Validated PayMongo webhook
- **Allocation authority:** `PaymentAllocationService`
- **Implementation sequence:** Tatapusin muna ang approved non-security payment work bago ang hiwalay na security implementation cycle
- **Security implementation status:** Planning at review only; hindi ito awtomatikong sisimulan pagkatapos ng ibang tasks

Pinagsama sa document na ito ang repository audit at approved payment-security boundary. Implementation plan lang ito—hindi pa ito authorization para mag-edit ng code, mag-deploy, o magbago ng database.

## Implementation Stop Boundary

Ang implementation ay hahatiin sa dalawang malinaw na cycle:

1. **Payment functionality at correctness cycle** — lifecycle, TEST/LIVE isolation, reporting, history, analytics, QRPh UI, exports, reconciliation, at deployment verification.
2. **Payment security implementation cycle** — isang bagong implementation effort na may sariling audit, threat model, design, approval, tests, rollout, at rollback plan.

Kapag natapos ang approved non-security payment tasks, **mandatory STOP** muna. Hindi awtomatikong tutuloy sa security code changes. Bago simulan ang security implementation, kailangang:

- i-audit ulit ang aktuwal na payment endpoints, data flows, secrets, uploads, webhooks, permissions, at deployment configuration;
- paghiwalayin ang Payment-module responsibilities at SMS2 Core/lead-programmer responsibilities;
- gumawa ng security threat model at prioritized findings;
- tukuyin ang exact files, schema dependencies, compatibility risks, at rollback plan;
- humingi ng explicit implementation approval sa user;
- humingi ulit ng hiwalay na approval bago ang anumang database migration, credential rotation, o destructive remediation.

Ang mga security item sa document na ito ay requirements at future security backlog muna hangga't hindi nakakalampas sa stop boundary na ito.

## 1. Scope Boundary

### Responsibility ng SMS2 Core / Lead Programmer

- Global authentication at login architecture
- Global session architecture
- Global RBAC framework
- Global password at user management
- System-wide CAPTCHA, 2FA, passkey, at security-header architecture
- Overall system security integration

Gagamitin lang ng Payment module ang authenticated identity, role, session, permissions, at CSRF helpers na binibigay ng SMS2 Core. Hindi tayo gagawa ng hiwalay o duplicate na security framework.

### Responsibility ng Payment Module

- Authentication at authorization ng payment endpoints
- Payment-specific permissions
- Ownership checks para sa payment, billing, concern, receipt, at reconciliation records
- CSRF protection sa payment mutations
- Server-side validation ng amount, billing, context, channel, at payment state
- Rate limiting para sa payment operations
- PayMongo webhook signature validation at idempotency
- Financial isolation ng TEST at LIVE
- Duplicate-payment at duplicate-allocation protection
- Payment concurrency at row locking
- QR attempt lifecycle security
- Authorization para sa Payment Concern/OCR at receipt protection
- Bank reconciliation authorization at duplicate controls
- Financial at lifecycle audit logging
- Secure payment configuration at secret handling sa loob ng module
- Accuracy ng official payment reports

## 2. Non-Negotiable Rules

1. `PaymentAllocationService` lang ang authoritative allocation engine.
2. Hindi proof of payment ang browser redirect, polling response, o success modal.
3. Validated PayMongo webhook lang ang puwedeng mag-confirm ng online payment.
4. Untrusted ang IDs, amounts, channels, contexts, at status na galing frontend.
5. Hindi dapat makabago ng production billing o official totals ang TEST transactions.
6. Hindi dapat hard-delete ang financial records at payment attempts.
7. Mananatili para sa audit ang Expired, Cancelled, Failed, at Rejected attempts.
8. Hindi dapat hulaan o paghaluin ang LIVE, TEST, UNKNOWN, at non-PayMongo records.
9. SMS2 Core DB ang authority para sa identity at permissions.
10. Payment DB ang authority para sa billing, payments, allocations, at reports.
11. Ipe-preserve ang existing user-owned changes at unrelated modules.
12. Walang payment secret na puwedeng i-render, i-log, i-commit, o isama sa export.

## 3. Hard Database Change Approval Gate

Kailangang huminto bago mag-execute ng kahit anong:

- `CREATE TABLE`
- `ALTER TABLE`
- pag-add, drop, modify, o change ng column
- pag-add o drop ng index
- pag-add o drop ng foreign key
- pagbabago ng ENUM
- paggawa, pagbabago, o pagtanggal ng trigger
- data migration, backfill, reclassification, reversal, o cleanup

Bago humingi ng approval, kailangang ibigay muna ang:

1. Exact database: SMS2 Core DB o Payment DB
2. Exact table
3. Existing schema
4. Proposed schema change
5. Bakit kailangan ang change
6. Mga dependent files at services
7. Anong problem ang maso-solve
8. Exact migration SQL
9. Impact sa existing data
10. Backward compatibility strategy
11. Exact rollback SQL
12. Risk level

Required workflow:

```text
Ma-identify ang schema dependency
  -> STOP sa schema boundary
  -> ipakita ang migration at rollback
  -> hintayin ang explicit approval
  -> i-verify ang backup
  -> i-apply ang migration
  -> i-apply ang matching code version
  -> local regression testing
  -> i-deploy bilang isang release ang schema at code
  -> i-verify ang deployed schema at behavior
```

Hindi puwedeng i-deploy ang code na umaasa sa bagong migration habang luma pa ang deployed schema.

## 4. Confirmed Architecture na Dapat I-Preserve

```text
Authenticated SMS2 user/session
  -> payment-specific permission at ownership check
  -> server-side request validation
  -> Payment DB attempt
  -> PayMongo
  -> signed webhook
  -> locked payment-state transition
  -> PaymentAllocationService
  -> payment_allocations
  -> billing_items
  -> billing summary
  -> history / analytics / Student Portal
```

Mga existing strength na hindi dapat masira:

- Main QR creation ay may authentication, CSRF, ownership validation, billing validation, rate limiting, at locking.
- QR attempts ay may authoritative server expiry at environment metadata.
- Payment DB clock ang basehan ng QR countdown.
- Paid webhook ay nagva-validate ng signature, currency, amount, state, at known environment.
- Nila-lock ng paid processing ang payment row at may event/allocation duplicate protection.
- Isang Payment DB transaction ang verification at allocation.
- Kayang i-reconcile ang authentic late payment pagkatapos mag-expire ang QR.
- May ownership validation at payment-row lock ang cancellation.

## 5. Confirmed High-Risk Findings

### P0 — TEST/LIVE Financial Isolation

- Nagsa-save ng `gateway_environment` ang QRPh creation, pero hindi ito sine-save ng GCash/Maya/Card checkout.
- Lahat ng `Verified` payments ang kasalukuyang sinasama ng analytics kahit TEST o UNKNOWN.
- Parehong allocation path ang ginagamit ng TEST at LIVE paid events.
- Dahil dito, posibleng mabago ng TEST activity ang totoong billing—not only analytics.
- `UNKNOWN` ang historical online rows na null ang environment; bawal silang hulaan.

### P0 — Official Reporting

- Lahat ng Verified payments ang kasama sa Admin at Accounting totals.
- Hindi nire-require ng official online totals ang `gateway_environment = 'live'`.
- Hinahalo ng Admin history ang official transactions at failed lifecycle attempts.
- Billing rows ang binibilang ng Fully Settled pero “Students” ang label.
- Hindi proven settlement net ang kasalukuyang “Net Collections.”

### P1 — Payment Endpoint Security

- May legacy checkout endpoint na nakaka-bypass sa current auth, ownership, CSRF, permission, at rate-limit chain.
- Walang payment-admin authorization ang isang PayMongo connection tester.
- Kulang sa explicit granular payment permission ang ilang student-search at reporting APIs.
- Walang CSRF at strict value whitelist ang gateway settings POST.
- May credential material sa tracked payment artifacts na kailangang i-rotate at i-remediate.

### P2 — Lifecycle at Timestamp Accuracy

- Lazy lang ang stale Pending reconciliation; walang nakitang scheduled reconciliation.
- Hindi kumpleto ang expiry lifecycle ng non-QR attempts.
- Puwedeng magpakita ang Student Payment History ng Resume hanggang ma-reconcile ng backend ang expiry.
- Kulang sa locked billing revalidation ang QR Resume.
- Nao-overwrite ng cancellation ang `payment_date`.
- Hindi covered ng current webhook state policy ang payment na pumasok pagkatapos ng local cancellation.
- Attempt creation time ang pinapakitang parang payment completion time sa ilang pages.

## 6. Official Financial Classification

```text
Official transaction = Verified
AND (
  Online na gateway_environment = live
  OR verified Walk-in
  OR Accounting-approved Payment Concern/Bank Transfer
)
```

| Payment type | Environment | Official reporting |
|---|---|---|
| Online PayMongo | LIVE | Isama kapag Verified |
| Online PayMongo | TEST | Huwag isama; technical audit lang |
| Online PayMongo | UNKNOWN | Huwag isama hangga't hindi reconciled |
| Walk-in/Cash | N/A | Isama kapag Verified |
| Approved Concern/Bank | N/A | Isama pagkatapos ng Accounting verification |

Bawal i-classify ang historical data gamit lang ang amount, student, current gateway mode, o guessed channel.

## 7. Accounting Definitions

- **Amount Applied:** `payments.amount`; ito ang ipinopost laban sa student billing.
- **Processing Fee:** `payments.processing_fee`; gateway/convenience fee.
- **Checkout Total:** `payments.checkout_total`; actual amount na siningil sa payer.
- **Official Collection:** Amount Applied mula sa production-eligible Verified transactions.
- **Settlement Net:** Kailangan ng provider payout/fee evidence; hindi ito automatic na katumbas ng Amount Applied.
- **Receivable:** Positive in-scope `billing.remaining_balance` na reconciled sa billing items.
- **Fully Settled Student:** Unique student na walang positive remaining balance sa lahat ng in-scope active billing.

## 8. Target Payment Lifecycle

```text
Payment request
  -> authorize user at object
  -> validate billing/context/amount/channel
  -> lock at i-check ang active attempt
  -> gumawa ng Pending na may environment at expiry
  -> tumawag sa PayMongo

Pending
  -> authentic paid webhook -> Verified
  -> explicit cancel -> Cancelled
  -> authoritative expiry -> Expired
  -> provider/create error -> Failed

Verified LIVE o official non-PayMongo
  -> PaymentAllocationService
  -> official billing at reporting

Verified TEST
  -> technical audit record lang
  -> walang production allocation
  -> hindi kasama sa official reporting
```

Kailangan ng explicit reconciliation policy para sa paid-after-expiry at paid-after-cancel. Kapag hindi kayang i-invalidate ang provider resource, hindi puwedeng tahimik na mawala ang authentic paid event.

## 9. Payment-Specific Security Settings

Hindi tayo maglalagay dito ng global login, password, o session controls.

### Payment Access

- Permission coverage ng Admin, Accounting, Cashier, at Student
- Page/API permission status
- Payment-object ownership enforcement status

### Online Payment Security

- Active PayMongo environment
- Credential configured status lang; never secret values
- Webhook signature at idempotency status
- QR duplicate protection
- Payment request rate limit
- Last successful signed webhook, kung mapapatunayan ng backend data

### Financial Integrity

- Allocation concurrency protection
- Duplicate-posting protection
- TEST/LIVE isolation
- Payment-state validation
- Bilang ng unresolved UNKNOWN-environment records

### Payment Concern / OCR

- OCR scan permission
- Secure receipt access at upload validation
- Duplicate receipt/reference protection
- Evidence lang ang OCR; hindi automatic approval

### Bank Reconciliation

- Import permission at CSRF
- Duplicate-file hash at bank-row protection
- Separation ng reconciliation evidence at Accounting approval

### Audit and Monitoring

- Payment lifecycle audit coverage
- Failed webhook events
- Unauthorized payment access
- Allocation failure at environment mismatch monitoring

Allowed evidence-based labels:

- ENFORCED
- CONFIGURED
- ENABLED
- RECENTLY SUCCESSFUL
- FAILED
- PARTIAL
- UNKNOWN

Huwag gumawa ng toggle kung walang totoong backend reader at enforcement point.

## 10. Required Payment Audit Events

- Payment/QR attempt created o duplicate blocked
- Payment expired o cancelled
- Webhook received, rejected, duplicate, o environment-mismatched
- Payment completed/verified
- Late payment reconciled
- Allocation completed o failed
- Payment Concern approved/rejected
- OCR scan/rescan
- Receipt access denied
- Bank import accepted/rejected at bank row reconciled
- Channel/environment/fee-policy changed
- Unauthorized payment page/API/object access

Dapat may actor kung applicable, action, object type/ID, result, PHT timestamp, correlation ID, at safe metadata. Bawal i-log ang secrets o buong sensitive provider payload.

## 11. Implementation Phases

### Required Execution Order

Hindi susundin nang diretso ang numeric listing para sa security phases. Ang authorized execution order ay:

```text
Cycle A — Payment functionality/correctness
  Phase 2 hanggang Phase 9
  -> Phase 11
  -> Phase 12 verification para lang sa implemented Cycle A scope
  -> mandatory STOP at user review

Cycle B — Dedicated payment-security implementation
  bagong security audit at threat model
  -> explicit user approval
  -> Phase 1 security containment
  -> Phase 10 security settings
  -> documentation-derived security hardening
  -> full security regression at controlled release
```

Ang authentication, authorization, validation, locking, idempotency, at environment checks na kailangan para hindi maging financially incorrect ang isang Cycle A feature ay mananatiling acceptance requirement ng feature na iyon. Pero ang broad security remediation/hardening ay hindi isasabay nang palihim at sakop ng Cycle B.

### Phase 1 — Payment Security Containment (DEFERRED TO CYCLE B)

- I-inventory ang bawat Payment at Student Portal payment endpoint at caller.
- I-enforce ang auth, exact permission, ownership, CSRF, at rate limits kung applicable.
- I-retire o i-hard-disable ang legacy direct endpoints pagkatapos ma-verify ang callers.
- Protektahan ang provider connection/status endpoints gamit ang payment-admin permission.
- I-sanitize ang provider/database error responses.
- I-rotate ang exposed payment credentials at alisin ang tracked secret artifacts gamit ang separately approved procedure.

**Exit criteria:** Hindi na makakagawa, makakapagbago, makakakita, o makakapag-probe ng payment resource ang direct unauthenticated request; reachable pa rin ang signed PayMongo webhook.

### Phase 2 — TEST/LIVE Financial Isolation

- I-persist ang environment sa bawat online attempt at resume path.
- I-validate ito laban sa signed webhook environment.
- Siguraduhing hindi makapag-allocate sa production billing ang TEST success.
- Panatilihin ang TEST sa technical audit views.
- I-classify bilang UNKNOWN ang historical null online environment.
- Gumawa ng read-only report ng TEST/UNKNOWN records na may allocations.
- Huwag mag-reverse ng history nang walang Accounting review at DB approval.

**Exit criteria:** Hindi naaapektuhan ng TEST ang production billing/totals; gumagana pa rin ang LIVE at official non-PayMongo flows.

**Current progress:** In progress. Na-persist na sa regular PayMongo checkout ang environment, LIVE-only na ang webhook allocation, at na-centralize na ang initial official-report predicate. Kailangan pa ang full caller/report inventory, deployed-database verification, historical TEST/UNKNOWN reconciliation report, at end-to-end TEST/LIVE regression bago ma-markang complete.

### Phase 3 — Timestamp at Audit Accuracy

- I-define ang PHT display at DB-session conventions.
- Itigil ang paghahalo ng `payment_date` at `created_at` time.
- Panatilihing magkahiwalay ang attempt, webhook, verification, allocation, expiry, at cancellation timestamps.
- Huwag basta mag-add ng walong oras o mag-rewrite ng ambiguous history.
- Mag-add ng structured events kung kaya ng current schema.
- Huminto sa DB gate kung kailangan ng bagong timestamp storage.

### Phase 4 — Pending/Expired/Cancelled/Resume Lifecycle

- I-reconcile ang stale Pending gamit ang webhook, status/page load, at scheduled processing.
- Panatilihin ang lahat ng attempts; walang hard deletion.
- Itago o i-disable ang invalid Resume action.
- I-revalidate sa Resume ang ownership, billing, payable balance, state, at expiry sa ilalim ng safe lock.
- Alisin ang unsafe client-controlled duplicate bypass.
- I-define ang cancel-vs-paid reconciliation.
- Sa valid QR Resume, gamitin ang parehong attempt, intent, at expiry.

### Phase 5 — Official Reporting Accuracy

- I-centralize ang official-report predicates.
- Itama ang Admin/Accounting totals at environment scope.
- Paghiwalayin ang Amount Applied, Processing Fee, Checkout Total, at settlement net.
- I-normalize ang channel presentation nang hindi hinuhulaan ang stored data.
- Itama ang Fully Settled bilang unique in-scope students.
- Malinaw na i-label ang LIVE, TEST, ALL/AUDIT, at UNKNOWN views.

### Phase 6 — Transaction History

- Magkaroon ng hiwalay na Official Transactions at Payment Attempts views.
- Mag-add ng server-side search, filters, sorting, pagination, at result count.
- Suportahan ang date, student, reference, channel, status, environment, type, at allocation context.
- Ipakita lang ang timeline events na mapapatunayan ng data.
- Itugma ang CSV export sa selected scope.
- Paghiwalayin ang no data at query failure states.

### Phase 7 — Admin Collection Analytics

- Default sa official Production/LIVE scope.
- Mag-add ng Today, Week, Month, Semester, Custom range, at PHT “As of.”
- Gumamit ng traceable collections, receivable, channel, fee, attempt, at transaction metrics.
- Paghiwalayin ang gateway configuration at actual recent operational evidence.

### Phase 8 — Cashier/Accounting Analytics

- Mag-focus sa verified operational collections.
- Mag-add ng cash, LIVE online, transaction count, fee, receivable, at settled-student metrics.
- I-scope ang Collections by Channel at Recent Verified Collections.
- Huwag i-expose ang payment-admin configuration controls.

### Phase 9 — QRPh UI Refinement

- I-preserve ang QR creation, polling, at expiry logic.
- Mag-add ng subtle bordered supported-institution container.
- I-standardize ang logo size, spacing, responsive wrapping, at alt text.
- Paghiwalayin ang QRPh banks/e-wallets at card networks.
- Magpakita lang ng support claims na backed ng authoritative evidence.

### Phase 10 — Payment Security Settings (DEFERRED TO CYCLE B)

- I-build ang evidence/status sections na nasa plan na ito.
- I-reuse ang SMS2 Core permissions at CSRF.
- Huwag mag-add ng global security controls o fake toggles.

### Phase 11 — Export, Audit, at Monitoring

- Mag-add ng scope metadata sa print/CSV.
- I-exclude ang TEST/UNKNOWN sa official exports.
- I-record ang webhook outcomes at lifecycle events.
- Mag-add ng safe operational views para sa failed webhooks at unresolved attempts.

### Phase 12 — Regression at Controlled Release

- Kumpletuhin ang testing matrix.
- I-verify ang schema/code compatibility.
- Mag-deploy muna sa controlled environment.
- I-verify ang HostForge health, schema, webhook reachability, permissions, at audit events.
- Controlled LIVE validation lang pagkatapos pumasa ang lahat ng P0/P1 criteria.

### Cycle B — Documentation-Derived Security Hardening

Hindi ito sisimulan automatically pagkatapos ng Cycle A. Kailangan muna ng bagong implementation proposal at explicit approval.

- Enforce HTTPS/TLS sa production Payment pages, APIs, webhook endpoint, at outbound provider communication.
- I-audit at i-convert sa prepared statements ang lahat ng payment SQL na gumagamit ng untrusted values.
- Gumawa muna ng sensitive-field inventory bago mag-design ng AES-256 encryption at key rotation.
- Panatilihin ang encryption keys sa approved secret storage; bawal sa source, database values, logs, exports, o repository.
- Gawing append-only at tamper-evident ang payment audit trail, subject sa kasalukuyang DB capabilities at DB approval gate.
- I-apply ang payment-data minimization, log redaction, retention, archival, at secure-disposal policy.
- I-harden ang receipt upload gamit ang content-signature/MIME validation, size at dimension limits, randomized filenames, non-executable storage, ownership-controlled access, at duplicate hash/reference checks.
- Panatilihing evidence lang ang OCR; required ang authorized Accounting review bago payment approval.
- I-standardize ang secure REST API baseline: auth, exact permission, ownership, CSRF, schema validation, rate limit, allowed methods, at sanitized errors.
- Magpatupad ng dedicated security test gate para sa authentication/RBAC, IDOR, SQL injection, malicious uploads, webhook replay/signature, TLS, encryption-at-rest, audit tampering, at sensitive-data leakage.
- I-coordinate sa lead programmer ang global password hashing, login/session architecture, system-wide RBAC, CAPTCHA/2FA/passkeys, at global security headers.

**Cycle B exit criteria:** Na-implement at na-test ang approved Payment-only security controls; walang duplicate global-security framework; walang exposed secret o sensitive payment data; documented ang residual risks at ownership.

## 12. File-Level Work Map

| Priority | File/area | Planned action |
|---|---|---|
| P0 | `api/paymongo/create-checkout.php` | I-persist ang environment at ayusin ang expiry/duplicate lifecycle |
| P0 | `api/paymongo/webhook.php` | I-enforce ang TEST/LIVE posting policy at event audit |
| P0 | `includes/PaymentHistoryService.php` | I-centralize ang official/attempt/report predicates |
| P0 | Admin/Accounting analytics | Palitan ang unsafe Verified-only totals |
| P1 | Legacy checkout endpoint | I-verify ang callers bago i-hard-disable/deprecate |
| P1 | PayMongo connection tester | Require payment-admin permission o i-retire |
| P1 | Student search/report APIs | Mag-add ng explicit auth at granular permission |
| P1 | Online Payment Integration POST | Mag-add ng CSRF at strict whitelists |
| P1 | Payment secret artifacts | I-rotate at alisin nang safe sa tracking |
| P2 | QR status/cancel/resume | Kumpletuhin ang lifecycle, locking, at audit |
| P2 | Student Payment History | Itago ang invalid Resume at i-reconcile ang stale state |
| P3 | History/export/report services | Mag-add ng environment/date/scope metadata |
| P4 | Account Balance QR UI | I-refine ang supported-institution presentation |
| Protected | `PaymentAllocationService.php` | Huwag galawin nang walang separately proven defect at approval |

## 13. Testing Matrix

### Endpoint Security

- Unauthenticated, unauthorized-role, at missing-permission requests
- Cross-student access sa payment, billing, QR, receipt, o concern
- Direct URL at crafted JSON/form requests
- Missing/invalid CSRF at rate-limit threshold
- Sanitized provider/database error responses

### PayMongo / QR

- Valid at invalid TEST/LIVE signatures
- Wrong amount, currency, environment, owner, o billing context
- Duplicate at concurrent webhook delivery
- Valid at expired Resume
- Expiry/payment at cancel/payment races
- Paid after expiry o cancellation
- Refresh, multiple tabs, QR download, at countdown

### Financial Isolation

- TEST QRPh/GCash/Maya/Card cannot allocate production billing
- LIVE QRPh allocates exactly once
- Walk-in at approved concern remain official
- Excluded ang UNKNOWN history
- Lumalabas sa reconciliation output ang existing TEST/UNKNOWN allocations

### Reporting

- Official LIVE, TEST audit, at combined audit views
- Lahat ng lifecycle statuses
- Cash/QRPh/GCash/Maya/Card/Bank grouping
- Fee policies, receivables, at unique settled students
- PHT/date boundaries, search, filters, pagination, CSV, at print
- No-data versus backend-error behavior

### Payment Concern / OCR / Bank

- Unauthorized scan/import/view
- Receipt MIME, size, path, at ownership validation
- OCR complete/partial/ambiguous/no-text/failure
- Duplicate receipt/reference/file/row
- Hindi automatic approval ang bank match
- Gumagamit ng `PaymentAllocationService` ang approved payment

## 14. Release Gates

### Bago Mag-Code

- Approved ng user ang implementation phase.
- Na-inventory at na-preserve ang dirty worktree.
- Identified ang exact files at rollback strategy.

### Bago Mag-Database Change

- Kumpleto ang DB Change Gate report.
- May explicit approval.
- Verified ang restorable backup.

### Bago Mag-Deploy

- Passed ang P0/P1 tests.
- Passed ang syntax at affected regression tests.
- Walang secrets sa diff o logs.
- Magkatugma ang code at schema versions.
- Confirmed ang webhook URL/environment/config nang hindi nilalabas ang secrets.

### Pagkatapos Mag-Deploy

- Passed ang health endpoint.
- Verified ang required Payment DB schema.
- Passed ang auth, payment permissions, at CSRF.
- Exactly once naproseso ang signed webhook.
- Hindi binago ng TEST ang official billing.
- Exactly once nag-allocate ang controlled LIVE transaction.
- Magkakatugma ang Student Portal, history, analytics, at audit logs.

## 15. Acceptance Criteria

- May tamang auth, exact permission, ownership, CSRF, validation, at rate limit ang payment endpoints kung applicable.
- Hindi kayang i-bypass ng legacy paths ang secured flow.
- Hindi kailanman binabago ng TEST ang production billing o official totals.
- Authoritative metadata ang basehan ng LIVE; hindi hinuhulaan ang UNKNOWN.
- Tama pa rin ang Walk-in at approved Payment Concerns.
- Retained pero excluded sa official views ang Expired/Cancelled/Failed attempts.
- Puwedeng i-Resume ang valid Pending; hindi puwede ang expired.
- Nare-reconcile ang stale Pending nang hindi JavaScript-only.
- Hindi makakapag-double-post o double-allocate ang concurrent/duplicate webhook.
- Explicitly handled ang paid-after-expiry/cancel.
- Magkahiwalay ang lifecycle timestamp meanings at naka-display sa PHT.
- Hindi pinagpapalit ang Amount Applied, fee, Checkout Total, at settlement net.
- Traceable sa Payment DB ang receivables at settled counts.
- Nakalagay sa report ang range, environment, scope, at generated PHT time.
- Totoong backend evidence lang ang ipinapakita ng Payment Security Settings.
- Auditable ang denied security actions at lifecycle events.
- Protected ang payment, OCR, receipt, at bank secrets/data.
- `PaymentAllocationService` pa rin ang allocation authority.
- Walang database change nang walang explicit approval.

## 16. Approval Checkpoints

- [ ] Approve Phase 1 — Payment Security Containment
- [ ] Approve Phase 2 — TEST/LIVE Financial Isolation
- [ ] Approve nang hiwalay ang bawat required database migration
- [ ] Approve Phases 3–4 — Timestamp at Lifecycle
- [ ] Approve Phases 5–8 — Reporting at Analytics
- [ ] Approve Phases 9–10 — QRPh UI at Payment Security Settings
- [ ] Approve Phases 11–12 — Audit, Testing, at Controlled Release
