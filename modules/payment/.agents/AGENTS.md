# Repository Guidelines

## Payment Management Skills

Repository-scoped Payment skills live in `.agents/skills/`. Use the smallest applicable set:

- `payment-frontend-uiux` — Payment screens, responsive layouts, tables, filters, forms, and UI states; never invent backend financial logic.
- `payment-backend-engineer` — PHP services/APIs, queries, transactions, integration, and performance.
- `payment-architecture` — actual-process analysis and BPMN/ERD/DFD/sequence/component documentation.
- `paymongo-online-payment` — PayMongo, QRPh/channels, attempts, expiry, webhooks, reconciliation, and TEST/LIVE isolation.
- `google-ocr-payment` — receipt OCR extraction, evidence validation, rescans, and Accounting-review integration.
- `payment-security` — Payment-only audits and separately approved Cycle B hardening; it does not replace global SMS2 security.

Skills cooperate through explicit handoffs. A frontend-discovered data defect goes to the backend skill; schema changes trigger the database gate; architecture conflicts go to the architecture skill; PayMongo/OCR security findings route to the security skill. Read `.agents/rules/payment-system.md` and the applicable implementation plan before changing Payment behavior.

## Project Structure & Module Organization

This is a PHP 8.1+ modular school-management application. Shared bootstrapping, authentication, layouts, and utilities live in `config/` and `includes/`. Business modules are under `modules/`; each generally contains `pages/`, `api/`, `includes/`, `database/`, and `assets/`. Payment-specific work belongs in `modules/payment/`, while student-facing payment screens are under `modules/student-portal/`. Root `assets/` contains shared frontend resources. Database definitions and migrations live in `database/` and module-level `database/` directories. Composer packages are installed in `vendor/`; never edit generated dependency files directly.

## Build, Test, and Development Commands

- `composer install` — install PHP dependencies from `composer.lock`.
- `php -l modules/payment/path/file.php` — syntax-check a changed PHP file.
- `Get-ChildItem modules/payment -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }` — lint the Payment module.
- `git diff --check` — detect whitespace and patch-format problems.

Run locally through XAMPP with Apache and MariaDB enabled, then open `http://localhost/SMS2_system/`. Verify `health.php` before testing workflows. There is currently no repository-level PHPUnit suite; perform focused manual regression tests for every affected role and flow.

## Coding Style & Naming Conventions

Use four-space indentation in PHP and JavaScript. Follow existing PHP conventions: PascalCase service classes (`BillingService`), camelCase methods/variables, kebab-case page filenames, and descriptive API filenames. Keep controllers thin and reuse module services instead of duplicating business logic. Use prepared statements for variable SQL and escape rendered output with `htmlspecialchars()`.

## Testing Guidelines

Test positive and negative paths, including unauthenticated access, incorrect roles, invalid input, empty/error states, and mobile layouts. Payment changes must cover duplicate/concurrent attempts, TEST/LIVE isolation, webhook retries, expiry/cancellation, and allocation correctness. Record manual test steps in the PR description.

## Commit & Pull Request Guidelines

Recent commits use short imperative summaries, for example `expand admin collection analytics UI`. Keep each commit scoped to one coherent change. PRs should explain the problem, changed modules, database impact, test evidence, rollback considerations, and include screenshots for UI changes. Link the relevant issue when available.

## Security & Agent Instructions

Never commit `.env`, credentials, private keys, tokens, receipt data, or production dumps. Database schema/data changes require explicit approval, exact migration and rollback SQL, and a verified backup. For Payment work, follow `.agents/rules/payment-system.md`, `.agents/payment-module-implementation-plan.md`, and the separately approved Cycle B security plan.
