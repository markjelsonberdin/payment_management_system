# SMS2 Payment Module — Security Implementation Plan (Cycle B)

## Document Status

- **Scope:** Payment Management module security lang
- **Status:** Planning only; hindi pa authorized for implementation
- **Start condition:** Tapos at accepted muna ang Cycle A, pagkatapos ay mandatory stop at explicit user approval
- **Database authorization:** Wala; bawat schema change kailangan ng hiwalay na approval
- **Credential authorization:** Wala; bawat secret rotation/revocation kailangan ng hiwalay na approval
- **Deployment authorization:** Wala; hiwalay ang implementation approval at production deployment approval
- **Global security owner:** SMS2 Core at lead programmer
- **Payment security owner:** Payment endpoints, records, workflows, integrations, at evidence

Ang document na ito ay execution blueprint para sa future Cycle B. Hindi ito automatic permission para mag-edit ng code, mag-run ng SQL, mag-rotate ng credentials, mag-delete ng files/data, o mag-deploy.

## 1. Security Boundary

### Sakop ng Payment Module

- Payment page/API authorization at object ownership
- Payment-specific CSRF, validation, throttling, at state checks
- PayMongo webhook authentication, replay/idempotency protection, at environment matching
- TEST/LIVE financial isolation, concurrency, duplicate posting, at allocation integrity
- Secure receipt upload/view/download
- OCR authorization, evidence integrity, at manual approval boundary
- Bank import/reconciliation security
- Payment audit trail, monitoring, secret usage, data minimization, retention, at encryption requirements

### Hindi Sakop / Coordinate sa Lead Programmer

- Global login/authentication at session architecture
- Password hashing/policy at account recovery
- System-wide RBAC framework
- CAPTCHA, 2FA, passkeys, lockout, at global security headers
- Organization-wide incident response

Hindi gagawa ang Payment module ng duplicate auth, session, RBAC, o audit framework. Gagamitin at ive-verify nito ang controls na galing sa SMS2 Core.

## 2. Non-Negotiable Security Rules

1. Hindi security control ang nakatagong UI; server-side enforcement ang kailangan.
2. Untrusted ang frontend IDs, amounts, channels, contexts, statuses, filenames, at redirects.
3. Validated PayMongo webhook lang ang external payment-completion authority.
4. `PaymentAllocationService` lang ang allocation authority.
5. OCR at bank match ay evidence lang; Accounting approval ang financial decision.
6. TEST/UNKNOWN cannot affect production billing o official totals.
7. Walang secret/private key/raw authorization/full sensitive payload sa source, logs, responses, exports, o Git.
8. Walang hard delete ng financial/audit history bilang remediation.
9. Walang database change nang walang DB Change Approval Gate.
10. Walang credential rotation/revocation nang walang explicit approval at recovery plan.

## 3. Mandatory Pre-Implementation Gate

Bago mag-security code changes, kailangan munang ihanda at ipa-approve ang:

- Complete inventory ng Payment pages, APIs, callers, jobs, webhooks, uploads, exports, at legacy routes
- Trust-boundary/payment-data-flow diagram
- Role, permission, at object-access matrix
- Threat model para sa Student, Cashier, Accounting, Admin, attacker, compromised session, forged webhook, at malicious upload
- Secret inventory na hindi ipinapakita ang values
- Sensitive-data inventory at retention requirements
- Current-control evidence: ENFORCED, PARTIAL, MISSING, o UNKNOWN
- Prioritized P0–P3 findings
- Exact files, schema dependencies, compatibility risks, tests, rollout, monitoring, at rollback
- Explicit user approval para simulan ang Cycle B

## 4. Risk Priorities

### P0 — Financial Compromise

- Forged/replayed webhook
- TEST/UNKNOWN posting sa production billing
- Amount, owner, billing, environment, state, o allocation tampering
- Duplicate/concurrent verification o allocation
- Exposed LIVE gateway secret

### P1 — Unauthorized Payment Access

- Unauthenticated/wrong-role endpoint
- Cross-student IDOR sa billing, payment, concern, receipt, QR, o history
- Missing CSRF sa state-changing cookie-authenticated request
- Legacy bypass route
- Arbitrary receipt path/file access

### P2 — Evidence at Data Protection

- Unsafe/executable upload
- OCR result na automatic approval
- Sensitive information sa logs/errors/exports
- Mutable o incomplete audit trail
- Missing retention/encryption design

### P3 — Hardening at Visibility

- Inconsistent rate limits
- Missing monitoring/status evidence
- Weak production configuration validation
- Kulang na abuse-case tests

## 5. Phase B1 — Endpoint at Caller Inventory

- I-map ang page/API sa JS/form caller at downstream service.
- Tukuyin ang public webhook versus authenticated browser endpoints.
- I-classify bawat route bilang read, mutation, upload, export, callback, o admin config.
- I-record ang auth, permission, ownership, CSRF, rate limit, validation, at error status.
- I-detect ang duplicate/legacy routes bago mag-retire ng kahit ano.

**Deliverable:** Payment Endpoint Security Matrix.

**Exit criteria:** Walang reachable Payment route na unknown ang caller at security expectation.

## 6. Phase B2 — Authentication, Permission, at Ownership

- Reuse SMS2 Core auth/session helpers.
- Require exact payment permission per sensitive page/API.
- Enforce student-own-records server-side.
- Validate Cashier, Accounting, at Admin scope.
- Check student → billing → payment/concern/receipt ownership chain.
- Retire only proven-unused legacy bypass routes.
- Log denied access gamit ang safe identifiers.

**Exit criteria:** Consistently denied ang unauthenticated, wrong-role, missing-permission, at cross-object requests.

## 7. Phase B3 — Request, CSRF, at Abuse Protection

- Strict method/content-type enforcement.
- CSRF sa cookie-authenticated mutations.
- Server-side allowlists para sa channel, environment, context, status, filters, at actions.
- Numeric/range validation at backend recomputation ng financial values.
- Per-user/per-object throttling para sa checkout, QR, cancel/resume, OCR, imports, at provider tests.
- Sanitized client errors; correlation-based server diagnostics.
- Prepared statements para sa lahat ng untrusted query values.

**Exit criteria:** Crafted form/JSON/query requests cannot bypass business o authorization rules.

## 8. Phase B4 — PayMongo at QR Security

- Reuse/audit `PayMongoWebhookSecurityService`; walang duplicate signature logic.
- Verify signature bago magtiwala sa event/environment/resource/amount.
- Validate timestamp/replay window at unique event ID.
- Lock payment row before state transition/allocation.
- Validate owner, billing, amount, currency, channel/context, state, at environment.
- Explicit paid-after-expiry at paid-after-cancel policy.
- Browser redirect/modal/polling is never payment proof.
- Configured status lang sa UI; never secret values.

**Exit criteria:** Forged, replayed, mismatched, duplicate, at invalid-state events cannot post/allocate money.

## 9. Phase B5 — Receipt Upload at Access

- Validate upload error, maximum size, dimensions, file signature, at detected MIME.
- Allowlist approved receipt formats.
- Random server filename; submitted path/name is never authority.
- Store outside executable/public paths when supported.
- Object-ID-based view/download pagkatapos ng auth at ownership check.
- Prevent traversal, URL guessing, duplicate file hash, at duplicate reference.
- Evaluate real malware scanning capability; walang fake control.

**Exit criteria:** Rejected ang malicious, oversized, spoofed, executable, traversal, at unauthorized receipt requests.

## 10. Phase B6 — OCR at Payment Concern Integrity

- Separate student claim, OCR extraction, bank evidence, at Accounting-verified values.
- Authorized reviewer lang ang OCR scan/rescan.
- Rate-limit bago external OCR call.
- Preserve attempt, result, reviewer, at timestamp.
- Support COMPLETE, PARTIAL, AMBIGUOUS, NO_TEXT, at FAILED.
- No automatic approval based on OCR confidence/match.
- Approved payment uses established service path at `PaymentAllocationService`.

**Exit criteria:** OCR cannot independently create, verify, or allocate official payment.

## 11. Phase B7 — Bank Reconciliation Security

- Exact import permission at CSRF.
- Validate CSV type, size, headers, encoding, at row schema.
- Track bank, file hash, batch ID, safe filename, uploader, timestamp, row count, at status.
- File-level at row-level duplicate detection.
- Preserve match/mismatch/not-found evidence.
- Bank match is not automatic Accounting approval.

**Exit criteria:** Duplicate/malformed/unauthorized imports cannot mutate official billing.

## 12. Phase B8 — Secrets at Production Configuration

- Inventory configured/missing status without secret values.
- Remove hardcoded secrets and rotate only after separate approval.
- Use protected environment secret storage at least-privilege credentials.
- Separate TEST/LIVE keys at webhook secrets.
- Fail closed sa unknown/mismatched environment.
- Require HTTPS sa production callback/payment endpoints.
- Disable verbose provider/database errors.

**Exit criteria:** Walang secret sa tracked files, rendered UI, client response, logs, exports, o artifacts.

## 13. Phase B9 — Sensitive Data, Encryption, at Retention

- Field-level data classification at minimization muna.
- Never store card number, CVV, wallet credentials, o unnecessary full payload.
- AES-256 field encryption only for proven-sensitive stored fields.
- Keys live outside DB/source at may versioned rotation design.
- Analyze searchable/indexed-field impact bago encryption.
- Define retention, archive, access, at approved secure disposal.
- Coordinate Data Privacy Act requirements with authorized institutional owner.

**DB gate:** Encryption metadata, columns, indexes, retention markers, o archive tables require separate approval.

**Exit criteria:** Verified ang minimum-data, encryption, access, at retention policy without breaking audit/reconciliation.

## 14. Phase B10 — Tamper-Evident Audit at Monitoring

- Reuse Core audit infrastructure kung compatible.
- Append-only financial/security events sa application layer.
- Record actor, action, object type/ID, result, PHT time, environment, at correlation ID.
- Never log secrets, full receipts, raw auth headers, o unnecessary payloads.
- Monitor rejected/replayed webhooks, environment mismatch, denied access, rejected uploads, allocation failures, at config changes.
- Hash-chained/tamper-evident storage requires separate design and DB approval if schema changes.
- Evidence labels only: ENFORCED, CONFIGURED, ENABLED, RECENTLY SUCCESSFUL, FAILED, PARTIAL, UNKNOWN.

**Exit criteria:** Traceable ang critical events at walang fake green status/toggle.

## 15. Database Change Approval Gate

Bago ang `CREATE`, `ALTER`, index/constraint/ENUM/trigger change, backfill, cleanup, reclassification, o reversal, kailangan ang:

1. Exact database/table at existing schema
2. Proposed schema at security requirement
3. Dependent files/services
4. Exact migration SQL
5. Existing-data at compatibility impact
6. Exact rollback SQL
7. Verified backup/restore strategy
8. Risk level at explicit approval

Code at schema dependencies must ship as one controlled release.

## 16. Security Testing Matrix

### Access Control

- Unauthenticated/expired session, wrong role, missing permission
- Cross-student/cross-billing IDOR
- Direct URL/API kahit hidden ang UI
- Receipt/export/object enumeration

### Request Security

- Missing/invalid CSRF at wrong method/content type
- Modified amount, ID, channel, environment, context, status, target
- SQL injection payloads sa search/filter/report/import
- Rate-limit threshold at recovery

### PayMongo

- Invalid TEST/LIVE signatures, replay timestamp, duplicate event
- Wrong currency/amount/environment/resource/state
- Concurrent events at paid-after-expiry/cancel
- TEST/UNKNOWN cannot allocate production billing

### Upload/OCR/Bank

- Spoofed MIME, executable/polyglot, oversized, traversal
- Unauthorized view/download/scan/import
- OCR partial/ambiguous/no-text/failure
- Duplicate receipt/reference/file/bank row
- OCR/bank cannot auto-approve

### Data Exposure

- Secrets/PII sa Git, logs, errors, HTML, JS, exports, backups
- Encryption round-trip/wrong-key/rotation/failure
- Audit append/tamper at retention/archive access

## 17. Controlled Rollout

1. Complete audit/threat model.
2. Approve exact security batch.
3. Implement smallest isolated changes.
4. Run syntax, negative, integration, at regression tests.
5. Apply separately approved migrations after verified backup.
6. Deploy sa controlled environment.
7. Verify permissions, webhook, uploads, audit, at TEST isolation.
8. Controlled LIVE validation only after P0/P1 pass.
9. Monitor at reconcile financial results.
10. Roll back sa authorization/payment/allocation/data-integrity regression.

## 18. Final Acceptance Criteria

- Complete Endpoint Security Matrix at threat model.
- Server-side auth, exact permission, ownership, CSRF, validation, at throttling.
- Walang legacy bypass.
- Forged/replayed/mismatched webhook cannot verify/allocate.
- TEST/UNKNOWN cannot affect production.
- Duplicate/concurrent events cannot double-post/allocate.
- Secure upload at object-authorized receipt access.
- OCR/bank cannot auto-approve.
- Walang exposed secrets at prepared statements ang untrusted queries.
- Verified data minimization, encryption scope, retention, at audit.
- Walang duplicate global-security framework.
- Passed negative, regression, at controlled LIVE tests.
- Documented residual risks, deferred items, owners, at monitoring.

## 19. Explicit Approval Checkpoints

- [ ] Approve Cycle B audit at threat model
- [ ] Approve prioritized findings at exact file scope
- [ ] Approve endpoint/access-control batch
- [ ] Approve PayMongo/QR hardening batch
- [ ] Approve receipt/OCR/bank hardening batch
- [ ] Approve credential rotation separately
- [ ] Approve each database migration separately
- [ ] Approve encryption/retention design
- [ ] Approve security monitoring/settings implementation
- [ ] Approve controlled deployment
- [ ] Approve controlled LIVE validation
