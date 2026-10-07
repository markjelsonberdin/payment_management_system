# MIS-1: MIS Admin implementation and RBAC audit

Date: 2026-10-04 (Asia/Singapore)

## Scope and evidence

Canonical rule: **MIS controls the system; Accounting controls the money.** MIS manages only Accounting Admin, Accounting Officer, and Cashier accounts. MIS cannot manage MIS, Super Admin, students, faculty, Registrar, or other non-Payment accounts.

This is a source audit of the current checkout: Payment page/API inventory, global authorization, navigation metadata, dashboard redirects, personnel mutations, gateway settings/status, security workflow, audit writer, and legacy migration paths. No application code, database records, credentials, integration settings, or deployment were changed. No AGENTS.md was found in the workspace search.

Validation: `C:\xampp\php\php.exe tests/payment-accounting-role-matrix-regression.php` passed **91 canonical PMS RBAC checks**, including isolated session cleanup. These are capability tests and source assertions; they do not prove live HTTP authorization, session revocation, database migration state, provider connectivity, or Hostforge deployment readiness.

## Existing MIS routes and dependencies

| Surface | Current implementation | Assessment |
|---|---|---|
| Generic landing | `dashboard/index.php:18` sends MIS to personnel management | Wrong landing; change during MIS-2 |
| Module hub | `modules/payment/index.php` selects MIS group and filters capability-owned cards | Reuse; fallback can render cards without capability filtering if the bundle becomes incomplete |
| Navigation | `config/config.php:308–311,332–334`; `includes/sidebar.php:366,398,425,456` | Three MIS pages only; dashboard shortcut is treated specially |
| MIS dashboard | `modules/payment/pages/mis_admin/dashboard.php` | Financial dashboard despite Technical Dashboard navigation title |
| Dashboard data | `modules/payment/api/payment-admin-dashboard-data.php` → `includes/PaymentAdminReportingService.php` | Legacy financial read service authorized by integration permission |
| Personnel page | `modules/payment/pages/mis_admin/payment-user-management.php` | Reusable three-role UI |
| Personnel client | `modules/payment/assets/js/accounting-user-management.js` | Shared historical Accounting naming |
| Canonical personnel API | `modules/payment/api/mis_admin/payment-users.php` | Wrapper around `api/accounting/accounting-users.php`; both enforce MIS capability via shared implementation |
| Integration page | `modules/payment/pages/mis_admin/online-payment-integration.php` | PayMongo only; mixed technical and financial settings |
| Integration APIs | `api/paymongo/status.php`, `channels.php`, `test-connection.php` | MIS capability guards; reusable technical status components with corrections below |
| OCR/AUB configuration | `integration.ocr.manage`, `integration.aub.manage` in authorization bundle | Permissions exist; dedicated MIS pages/API handlers absent from current Payment inventory |
| Roles/access, security, system settings, audit | No dedicated MIS pages in Payment inventory/navigation | Missing portal modules |

## Findings, ordered by priority

### P1 — Financial reporting is accessible through a technical permission

`pages/mis_admin/dashboard.php:5` and `api/payment-admin-dashboard-data.php:6` require `integration.paymongo.manage`. The dashboard exposes verified Live collection amounts, allocation trends/channel totals, and recent student transactions. `PaymentAdminReportingService::load()` queries `payments`, `payment_allocations`, and `students` and returns amounts, student names/numbers, and references.

This is a confirmed canonical-scope violation even though MIS is denied `report.view`. Integration permission must not authorize financial reporting. Replace the dashboard with scoped account/security/admin metrics and technical health. Retire this MIS data endpoint, or move any retained financial service behind Accounting reporting permission and a deliberate Accounting consumer. Do not merely hide financial cards while leaving the API accessible.

### P1 — MIS can change who pays processing fees

`pages/mis_admin/online-payment-integration.php:32,59,317–319` saves `fee_policy` (`pass_to_student` / `absorb_by_school`). Both `api/paymongo/create-checkout.php:146` and `create-qr-payment.php:157` consume it when calculating checkout fees. This changes monetary treatment and belongs to Accounting financial policy. Remove MIS write ownership in both UI and server handler; preserve the existing value during technical settings saves. Assign a separately authorized Accounting workflow in a later financial-policy task.

### P1 — Gateway settings save does not validate CSRF or enum values

The integration form renders `csrfField()` at line 213, but its POST save block at lines 22–89 never calls `verifyCsrfToken()`. It also accepts arbitrary `gateway_mode` and `fee_policy` strings. Enforce CSRF before writes and allowlist supported technical modes. The existing transaction does not substitute for authorization/input validation. Error redirects currently expose raw exception messages; replace with safe user-facing errors and server-side diagnostic logging.

### P1 — Personnel security mutations do not revoke existing sessions

`api/accounting/accounting-users.php` scopes edits/status/password resets correctly, but writes role, status, or password without invoking account session revocation. `requireAuth()` uses session identity; the reviewed session/force-logout path checks kick epochs rather than current account status/role/password version. `smsForceLogoutUsers()` already exists in `includes/module-controls.php:186` and can be reused with appropriate race-safe epoch semantics.

Source evidence indicates an already authenticated user can retain old role/session access after deactivation, role change, or password reset. Reproduce in an isolated two-session HTTP test before claiming runtime proof, then enforce revocation and current-account validation consistently across pages and APIs. PayMongo status/channels/test-connection use `isAuthenticated()` directly rather than `requireAuth()`, so they also skip its maintenance/force-logout enforcement.

### P2 — Security visibility and administrative history are incomplete

Personnel list response omits `failed_login_attempts`, `locked_until`, and login history. There is no explicit unlock action, scoped lockout/security dashboard, scoped audit viewer, or dedicated security alert module. Status changes implicitly clear login failures/locks, conflating activation with unlock. Define explicit actions and distinguish inactive, temporarily locked, and any supported suspended state.

User changes log `accounting_user_*` text events, but role edits do not capture structured before/after role values. Gateway saves log channel changes only; gateway-mode and fee-policy changes lack equivalent events. `includes/audit.php::logActivity()` silently returns without a DB and catches insert failures, allowing successful writes without a durable audit entry. Reuse structured audit/outbox patterns where appropriate; redact secrets and scope logs by permitted actors/targets/event types. A generic Payment-module log filter alone could include financial events and is insufficient for MIS.

### P2 — Gateway readiness can report an unrelated webhook as ready

`api/paymongo/status.php` computes `$expectedWebhookUrl`, but readiness only tests mode, enabled status, and required events. It does not compare the provider webhook URL to the expected endpoint. Check URL and deployment environment before reporting readiness. Configuration presence, authenticated provider connectivity, registered webhook configuration, and recent successful delivery should remain separate indicators.

### P2 — Dashboard permission and module vocabulary need independent capabilities

Overview is tied to PayMongo configuration permission. There are no distinct MIS overview, scoped security/audit read, or technical-system configuration capabilities. Define these in the canonical server bundle and navigation metadata before adding pages; unknown capabilities must continue to deny. Role assignment should remain limited to the three fixed Payment roles, rather than allow MIS to rewrite their financial permission bundles.

### P2 — Navigation/dependency remnants

The integration page uses `$activePage = 'online-payment-integration'` at line 122, unlike canonical `mis_admin/online-payment-integration` navigation metadata. Align it for highlighting/breadcrumbs.

`includes/authentication.php:485` references `includes/navigation-context.php`, which is absent in this checkout. That helper path should be repaired or retired after checking callers; the inspected login page currently redirects to the generic dashboard, so this is not evidence that every login currently fails. Confirm this dependency and all landing entry points during MIS-2.

## RBAC boundaries already worth retaining

- `paymentRoleAllowsPermission()` (`includes/authentication.php:1653`) uses fixed canonical role bundles and defaults unknown roles/permissions to denial. MIS has only five personnel capabilities and three integration capabilities. Legacy page keys map to canonical capabilities.
- Financial page/API guards inspected cover fee setup, billing, bulk processing/approval, concerns/OCR/AUB financial import and reconciliation, ledger/AR, collections, reports/exports, and School Sales. MIS lacks their capabilities. Secure concern receipt reads delegate to `PaymentSecurityService`, which checks canonical permission for non-students.
- Personnel API requires authentication, `payment_users.view`, POST, CSRF, and per-action capabilities. Create/edit role values are allowlisted; existing target reads and mutation WHERE clauses restrict all three permitted roles. There is no hard-delete action. Passwords are hashed, validated, and temporary passwords force a change at subsequent login.
- The Accounting-named personnel endpoint is a compatibility route, not evidence that Accounting can manage accounts; the canonical MIS wrapper reaches the same guarded handler.
- Secret/webhook key strings displayed by the integration page are masked; retain that approach and avoid secrets in responses/logs.
- Legacy `api/payment-webhook.php` explicitly returns 410. Preserve retirement behavior; the signed PayMongo webhook remains an independent machine endpoint, not an interactive MIS financial operation.

## Deprecated code / migration assessment

`PaymentAdminReportingService`, `payment-admin-dashboard-data.php`, `payment-admin-dashboard.js`, and Accounting-named personnel components retain historical Payment Admin concepts/naming. Remove financial use from MIS; do not mass-delete shared code without checking remaining consumers.

`database/migrations/payment_mis_admin_role_conversion.sql` converts Finance users to MIS and revokes old `finance`/`payment_admin` Payment grants. It deliberately preserves historical role/audit rows and leaves obsolete-account retirement to a separate preflight. Capability tests deny those legacy roles. No migration was executed and no live grant/account state was verified. Deployment validation must check active legacy users, historical granular grants, navigation, and stale clients before retirement.

## Recommended implementation sequence

1. **MIS-2:** Replace financial MIS overview and data endpoint; correct generic landing route, labels, active slug, and any used home-helper dependency. Include only three-role account counts, lockouts, scoped admin activity, safe technical health, and relevant quick actions. Missing integrations should honestly show unavailable/unconfigured.
2. **MIS-3 / MIS-4:** Reuse personnel CRUD; add explicit scoped security actions, session revocation, consistent API authentication, before/after audit data, and exhaustive negative HTTP authorization tests. Preserve fixed three-role assignment and reject attempts targeting any other role.
3. **MIS-5:** Correct PayMongo CSRF/input/audit/readiness issues and remove MIS fee-policy writes. Implement OCR and AUB technical configuration independently; keep receipt approval, statement matching, and reconciliation on Accounting.
4. **MIS-6:** Build scoped security/audit views and configuration history using existing login-throttle and activity infrastructure with reliable persistence and secret redaction.
5. **MIS-7:** Standardize UI/navigation after those boundaries are enforced.
6. **MIS-8:** Validate all routes as MIS/Accounting Admin/Officer/Cashier/student/Super Admin/legacy/unknown roles; forbidden target IDs, session invalidation, CSRF failures, configuration error states, and deployed environment/migration/provider behavior. Preserve machine-webhook signature verification.

## Completion boundary

MIS-1 source audit and existing role regression are complete. This report does not certify the portal as canonical: the financial dashboard, fee-policy ownership, CSRF, and session-lifecycle gaps require implementation and runtime verification. Browser, live database, provider, and Hostforge validation remain outstanding for the relevant later stages.
