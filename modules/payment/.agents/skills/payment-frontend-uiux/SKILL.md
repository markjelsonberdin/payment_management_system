---
name: payment-frontend-uiux
description: Design, review, and implement UI/UX for SMS2 Payment Management pages across Admin, Accounting/Cashier, and Student Portal. Use for layouts, tables, filters, forms, modals, responsive behavior, accessibility, and UI states; do not use it to invent or replace authoritative financial logic.
---

# Payment Frontend UI/UX

## Purpose

Improve Payment Management interfaces while preserving the repository's authoritative backend, permissions, and financial behavior.

## Required Context

Before acting, read:

- [Repository agent guide](../../AGENTS.md)
- [Payment rules](../../rules/payment-system.md)
- [Cycle A implementation plan](../../payment-module-implementation-plan.md)

Inspect the target page, its styles/scripts, API or form caller, response contract, shared layout, and role-specific navigation before editing.

## Scope

- Admin payment overview, fee setup, online configuration, transaction history, collection analytics, and future Payment Security Settings UI
- Accounting/Cashier billing, collection, discounts, ledger/history, analytics, concern review, and bank reconciliation
- Student account balance, online/QRPh payment, history, concern, success, failure, resume, and expiry views
- Hierarchy, navigation, typography, spacing, cards, tables, forms, modals, search, filters, pagination, icons, accessibility, responsive design, and loading/empty/error/success states

## Workflow

1. Trace displayed values and actions to their authoritative backend sources.
2. Identify the user role, decision to support, and mobile/desktop constraints.
3. Reuse shared layout, Bootstrap, and existing icon/design patterns.
4. Implement the smallest coherent UI change without duplicating backend rules.
5. Test real data, no-data, error, long-content, permission, and responsive states.

## Architecture Rules

- Backend data owns amounts, balances, statuses, allocation, environment, and payment completion.
- A success modal, redirect, or client timer never proves payment success.
- UI labels must distinguish Amount Applied, Processing Fee, Checkout Total, official LIVE data, TEST, and UNKNOWN when applicable.
- Keep Admin, Accounting/Cashier, and Student actions aligned with their actual permissions.

## Forbidden Actions

- Do not calculate authoritative balances or payment status independently in JavaScript.
- Do not hide a backend defect with a frontend workaround.
- Do not transplant alternate PHP architecture with a visual design.
- Do not expose secrets, raw provider errors, filesystem paths, or private receipts.
- Do not claim completion when only the query/backend or only the visual shell is finished.

## Handoffs

- Wrong API/data/query: hand off to `payment-backend-engineer`.
- Process or source-of-truth conflict: hand off to `payment-architecture`.
- PayMongo lifecycle issue: hand off to `paymongo-online-payment`.
- Security defect: report it for `payment-security`; do not start deferred Cycle B without approval.

## Validation Checklist

- Values match backend responses and official reporting scope.
- Actions work for the correct role and state.
- Search/filter/pagination/export scopes agree.
- Loading, empty, error, success, disabled, and permission states are distinct.
- Keyboard focus, labels, contrast, alt text, and mobile layout are usable.
- Changed PHP/JS passes relevant syntax and regression checks.

## Acceptance Criteria

The screen is responsive, accessible, audit-readable, role-correct, backed by authoritative data, and introduces no duplicate financial logic.
