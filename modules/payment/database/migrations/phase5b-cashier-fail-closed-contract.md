# Phase 5B Cashier School Sales Fail-Closed Contract

Cashier School Sales remains disabled throughout Phase 5. This file defines later application work only; Phase 5B does not modify PHP or enable routes.

## Feature gate

Use an explicit School Sales Cashier feature flag whose missing, invalid, or false value means disabled. There is no permissive fallback. The flag must be evaluated server-side; client-side hiding alone is insufficient.

## Navigation and dashboard

When disabled, hide every Cashier School Sales navigation item, dashboard card, shortcut, deep link, and actionable API discovery entry. Ordinary catalog administration access does not imply selling access.

## Direct request behavior

Every legacy Cashier School Sales page/action/API must check the server-side gate at the earliest entry point. A disabled request returns a fail-closed `404` or approved `503 FEATURE_DISABLED` response before requiring the legacy service file, constructing `CashSaleService`, loading sale data, validating a cart, or starting a transaction.

`CashSaleService` must not be modified, constructed, or invoked during Phase 5. No compatibility adapter is introduced yet.

## Forbidden effects

While disabled, direct requests cannot create or change sale, sale-item, receipt, inventory, billing, fee, assessment, AR, debt, payment, or allocation records. They also cannot reserve receipt numbers or emit a successful-sale audit event.

## Later acceptance tests

- Missing/false/malformed flag hides all navigation and dashboard entry points.
- Direct GET/POST/API requests fail before service load/construction.
- Crafted parameters cannot bypass the gate.
- Disabled requests produce no database writes.
- Enabling unrelated catalog-admin features does not enable Cashier selling.
- Static/instrumented test proves zero `CashSaleService` constructor or method calls.

These behaviors cannot be verified by Phase 5B SQL. They remain `NOT YET VERIFIED` until the later PHP implementation and route/UI tests are approved and completed.
