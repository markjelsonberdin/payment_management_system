# PROJECT STATUS PROMPT — FIXING THE PAYMENT MANAGEMENT SYSTEM

## Current Program

We are currently executing the larger repair plan called:

**FIXING THE PAYMENT MANAGEMENT SYSTEM**

The purpose of this plan is to stabilize and reconcile the entire SMS2 Payment Management System before doing any further feature expansion or redesign.

The main problems being resolved are:

* dashboard values not consistently matching database-backed financial records;
* different modules using different definitions for the same financial data;
* some reports using payment header amounts while others use allocation amounts;
* inconsistent handling of payment timestamps and reporting dates;
* Cashier query/runtime defects and history/export inconsistencies;
* operational payment attempts being mixed conceptually with official financial collections;
* raw technical timestamp/range information being exposed in the dashboard UI;
* incomplete error-state handling that can make valid data appear as zero or empty;
* historical Online payments with unresolved `gateway_environment = NULL`;
* dashboards, ledger, exports, and transaction history not yet fully reconciled under one source-of-truth model.

---

# What We Are Trying to Achieve

The final target is one consistent financial flow:

```text
Billing
→ Billing Items
→ Payment
→ Verification
→ Payment Allocation
→ Ledger
→ Accounts Receivable
→ Collection Reporting
→ Dashboard
```

Every layer must use compatible definitions and reconcile with the previous and next layer.

The dashboards must show actual business information from the database, not mock values, duplicated calculations, technical debug output, or inconsistent reporting rules.

The system should distinguish clearly between:

```text
Payment Attempt
vs
Official Financial Collection
```

For academic collections, the authoritative financial amount remains:

```text
payment_allocations.allocated_amount
```

not `payments.amount` when allocations exist.

For official Online financial reporting:

```text
transaction_type = Online
payment_status = Verified
gateway_environment = live
```

plus qualifying allocation totals.

---

# Current Phase

We are currently in:

**PHASE 1 — DASHBOARD UI CLEANUP + REPORTING CONSISTENCY**

This phase is not a redesign phase and not a database migration phase.

Its purpose is to make the current dashboards and reporting layers use consistent definitions and present clean, reliable data.

---

# What Phase 1 Is Resolving

## 1. Dashboard presentation cleanup

Keep:

```text
Today
Week
Month
Year
```

including Month/Year selection and existing reporting helpers.

Preserve:

* Asia/Manila handling;
* current-period cutoffs;
* historical period boundaries;
* same-elapsed prior-period comparisons;
* backend period parameters and metadata.

Remove only:

* raw start/end timestamp ranges;
* timezone banners;
* SQL-like date text;
* developer/debug-style reporting information from the UI.

Do not remove real transaction timestamps from the database or transaction records.

---

## 2. Reporting consistency

Align financial reporting so that:

* Ledger “Applied” amounts use qualifying allocation totals;
* Dashboard collection totals use allocations;
* Ledger exports use the same rules as the ledger screen;
* allocation rows are aggregated before joining payment records to avoid duplicate transaction totals;
* official collection date filtering uses verification time;
* operational attempt creation time stays separate from financial posting time.

---

## 3. Cashier consistency fixes

Resolve:

* Cashier History reference alias inconsistency;
* XLSX reference mapping;
* Cashier History/XLSX UNION collation exposure;
* recent activity data consistency;
* authenticated-cashier ownership rules.

The current Cashier dashboard collation fix already exists; Phase 1 must ensure related history/export surfaces follow the same safe behavior.

---

## 4. Error-state correctness

The dashboard must distinguish:

```text
valid zero
no_data
optional section error
fatal/core error
```

A failed optional chart/table must not wipe successful KPI data.

A failed core query must not be reported as a valid zero.

Frontend refresh logic must also prevent stale older responses from overwriting newer filter results.

---

## 5. Trend and comparison behavior

Keep prior-period comparisons.

Ensure:

* current period with data works;
* current period with zero works;
* current period empty but prior period has data still renders correctly;
* chart totals reconcile to card totals under the same scope.

---

## 6. Historical Online reconciliation

Produce a read-only reconciliation report for the snapshot’s historical Verified Online payments with:

```text
gateway_environment = NULL
```

The report must include:

* payment ID;
* provider reference;
* payment status;
* current environment;
* header amount;
* allocation total;
* billing affected;
* available provider evidence;
* confirmed or unresolved classification;
* required next evidence/action.

Do not automatically classify NULL as Live or Test.

Do not reverse allocations.

Do not change balances.

Any required historical correction must stop with:

**DATABASE CHANGE REQUIRED — USER APPROVAL REQUIRED**

---

# What Phase 1 Is NOT Doing

These are deferred:

* net-balance validation repair;
* cancelled-but-later-paid reconciliation;
* bank transfer allocation-targeting redesign;
* OCR rescan-history redesign;
* database backfill;
* schema changes;
* Reporting Period V2;
* global navigation redesign;
* deployment.

Do not expand Phase 1 into those areas.

---

# Phase 1 Success Criteria

Phase 1 is complete only when:

```text
Dashboard period behavior remains unchanged
Raw technical timestamp UI is removed
Accounting values reconcile within identical scope
Payment Admin attempt counts and official Live totals remain distinct
Cashier ownership remains authenticated-user scoped
Ledger Applied amounts reconcile to allocations
Ledger screen and export use the same financial definitions
Multi-allocation joins do not duplicate payment totals
Cashier History search works
Cashier XLSX reference works
Cashier UNION queries run without collation error
Optional errors remain isolated
Core errors are not converted into zero
Stale frontend responses cannot overwrite fresh data
Current-empty/prior-data trend renders correctly
Historical NULL-environment reconciliation report is produced read-only
```

Static validation and runtime validation must be reported separately.

Do not claim runtime PASS without actual execution.

---

# Current Status

We have already:

* analyzed the production SQL snapshot;
* confirmed that the main billing/allocation balances are internally reconcilable;
* identified historical NULL-environment Online allocations;
* confirmed Cashier UNION collation defects;
* confirmed reporting-definition inconsistencies between Ledger and dashboards;
* defined the authoritative source-of-truth rules;
* locked the Phase 1 scope.

We are now at the **implementation and validation stage of Phase 1**.

The next work must focus only on the locked Phase 1 tasks.

Do not redesign the entire Payment Management System.

Do not modify the database without explicit approval.

Do not stage, commit, push, or deploy unless separately authorized.
