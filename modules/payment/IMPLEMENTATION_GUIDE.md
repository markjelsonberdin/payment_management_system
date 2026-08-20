# Billing and Account Balance Integration Guide

This document outlines how the Payment Management System integrates with the Student Portal to provide a seamless billing lifecycle.

## Overview of the Flow

The system operates in three main phases:

### PHASE 1 — Billing Generation
1. **Admin (Fee Setup)**: The Finance Admin creates and configures active fees in the `fee-setup-configuration.php` module. These are saved to `payment_db.fees` and categorized in `payment_db.fee_categories`.
2. **Cashier (Invoicing)**: 
   - The Cashier opens `student-billing-invoicing.php` and clicks "Generate Billing".
   - The cashier types a student number. The system uses an AJAX call to `api/search-student.php` to live-validate the student number against `payment_db.students`.
   - The cashier selects the applicable fees (populated dynamically from the Admin's active fees).
   - Upon submission, the backend `BillingService` validates the IDs and generates the main `billing` record and individual `billing_items`.

### PHASE 2 — Student Account Balance
1. **Student Login**: The student accesses the Student Portal.
2. **Account Balance View**: The student navigates to `account-balance.php`.
3. **Data Retrieval**: 
   - The page queries `payment_db.billing` to get the latest billing snapshot for the student.
   - It queries `payment_db.billing_items` joined with `payment_db.fees` to display the exact breakdown of charges.
4. **Display**: The UI dynamically renders the total assessment, total paid, remaining balance, and the line-by-line status of each fee.

### PHASE 3 — Statement of Account (SOA)
1. **Request SOA**: In the Account Balance page, the student clicks "Request Statement of Account".
2. **Redirection**: The system detects `?process=soa` and redirects the student to the dedicated `statement-of-account.php` view.
3. **Printable Generation**: The SOA page fetches the same billing items but formats them into a clean, printable HTML document resembling a formal school invoice.
4. **Print**: The student can press the "Print SOA" button to trigger the browser's native `window.print()` dialog.

---

## Technical Files Modified / Created

- **`modules/payment/pages/accounting/student-billing-invoicing.php`**: Contains the modal and the frontend checklist for the fees.
- **`modules/payment/assets/js/billing-invoicing.js`**: Contains the JavaScript for the live AJAX student search.
- **`modules/payment/api/search-student.php`**: The JSON endpoint that queries the student details.
- **`modules/student-portal/pages/account-balance.php`**: The student-facing ledger that joins `billing` and `billing_items`.
- **`modules/student-portal/pages/statement-of-account.php`**: (NEW) The printable invoice interface.
