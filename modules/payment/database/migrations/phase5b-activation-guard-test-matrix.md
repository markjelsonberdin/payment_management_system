# Phase 5B Activation Guard Test Matrix

Status legend: `SQL-CHECKABLE` means the validation SQL can inspect current persisted consistency. `IMPLEMENTATION-TEST` means it cannot be claimed verified until later PHP/API and automated tests exist.

| Case | Setup / action | Expected result | Verification |
|---|---|---|---|
| A01 | Activate item with zero Active variants | Reject | IMPLEMENTATION-TEST |
| A02 | One Active variant with exactly one current Active price | Allow if all other rules pass | IMPLEMENTATION-TEST |
| A03 | Two Active variants; only one has a current price | Reject whole item | IMPLEMENTATION-TEST |
| A04 | Active variant has zero current Active prices | Reject | SQL-CHECKABLE + IMPLEMENTATION-TEST |
| A05 | Active variant has two current Active prices | Reject | SQL-CHECKABLE + IMPLEMENTATION-TEST |
| A06 | Draft/Inactive variant lacks a price | Does not block solely for that reason | IMPLEMENTATION-TEST |
| A07 | Active price starts in future only | Reject activation now | IMPLEMENTATION-TEST |
| A08 | Current Active price ends exactly now | Treat as not current; reject if no replacement | IMPLEMENTATION-TEST |
| A09 | Insert/activate overlapping Active price windows | Reject | SQL-CHECKABLE current state + IMPLEMENTATION-TEST enforcement |
| A10 | Modify amount of an already activated price | Reject; create successor price instead | IMPLEMENTATION-TEST |
| A11 | ALL with zero Active assignments | Allow | SQL-CHECKABLE + IMPLEMENTATION-TEST |
| A12 | ALL with one Active assignment | Reject | SQL-CHECKABLE + IMPLEMENTATION-TEST |
| A13 | RESTRICTED with zero Active assignments | Reject | SQL-CHECKABLE + IMPLEMENTATION-TEST |
| A14 | RESTRICTED with at least one Active assignment | Allow if all other rules pass | IMPLEMENTATION-TEST |
| A15 | RESTRICTED -> ALL | Deactivate all Active assignments; delete none | IMPLEMENTATION-TEST |
| A16 | Duplicate Active applicability scope | Reject by generated-scope unique key | SQL-CHECKABLE/database constraint |
| A17 | BOOK item with otherwise valid variants/prices | Reject `BOOK_METADATA_CAPABILITY_REQUIRED` | IMPLEMENTATION-TEST |
| A18 | STANDARD non-configurable item | Requires an Active `STANDARD` variant and current price | IMPLEMENTATION-TEST |
| A19 | Two concurrent price activations that would overlap | Exactly one succeeds | IMPLEMENTATION-TEST (concurrency) |
| A20 | Concurrent item activation and variant deactivation | No invalid Active item may commit | IMPLEMENTATION-TEST (concurrency) |
| A21 | New correlation + committed mutation/outbox | Exactly one mutation and one outbox row | IMPLEMENTATION-TEST |
| A22 | Same correlation + same fingerprint replay | Return prior committed result; no duplicate mutation/event | IMPLEMENTATION-TEST |
| A23 | Same correlation + different fingerprint | Reject 409 conflict | IMPLEMENTATION-TEST |
| A24 | Different correlations + identical fingerprints | Both are eligible independent requests | IMPLEMENTATION-TEST |
| A25 | Payment commits; Core unavailable | Business result stays committed; outbox retries | IMPLEMENTATION-TEST |

The SQL validation report must never label A01-A25 service behavior as proven merely because current tables contain no violating rows.
