# 📚 SMS 2: Payment Management System - Master Architecture Summary
**Bestlink College of the Philippines (BCP)**

Eto yung official and comprehensive technical architecture ng ating Payment Management System. Nakadetalye dito ang LAHAT ng processes, module-to-module integration, data flows, at kung paano tumatakbo yung buong financial ecosystem ng SMS 2.

---

## 🏛️ 1. Core Architecture & Security Foundation

Ang pinaka-pundasyon ng system ay nakasandal sa isang **Strict Role-Based Access Control (RBAC)** matrix. Ibig sabihin, strictly segmented ang responsibilities at isolated ang access base sa role ng user.

### A. The RBAC Matrix
- **Super Admin**: Strictly for User Management and System Administration. **TINANGGAL** ang access nila sa Payment transactions para maiwasan ang tampering.
- **Finance Admin (`finance` role)**: 
  - May hawak ng **ADMIN PORTAL** (`Fee Setup`, `Online Payment Configuration`).
  - May hawak ng **ADMIN REPORTING** (`Transaction History`, `Collection & Analytics`).
  - **WALA** siyang access sa collection (Cashier portal) para ma-maintain ang *separation of duties*.
- **Cashier (`cashier` role)**:
  - Sila lang ang pwedeng gumamit ng **CASHIER PORTAL** (`Student Billing`, `Payment Collection`, `Payment History & Ledger`).
  - Sila ang nagpo-process ng walk-in payments at verification ng manual deposits.
- **Student (`student` role)**:
  - May access sa **STUDENT PORTAL** (`Account Balance`, `Online Payment`, `Payment Concerns`).

### B. Security Architecture
1. **Module & Page Security**: Ginagamit natin ang `requirePaymentPermission('permission_key')` function. Kapag walang access ang role sa specific feature, automatic block (403 Forbidden).
2. **Environment Isolation (PayMongo)**: Mayroon tayong Live/Test toggle settings na naka-save sa database. Ang webhook processing natin ay nag-v-verify ng signature para walang dummy payloads ang makapasok sa production.
3. **Database Isolation**: Naka-separate ang `payment_db` from `sms2_db`. Naka-join na lang for references.

---

## 🔄 2. Complete Module Processes & Integrations

Ganito nag-uusap at nagco-connect ang bawat modules natin:

### Module 1: Fee Setup & Configuration (Admin)
- **Process**: Dito gumagawa ang Finance Admin ng master list of fees (Tuition, Misc, Adjustments).
- **Integration**: Ang mga *Active* fees dito ang SIYANG GINAGAMIT ng Cashier sa Student Billing. Bawal maningil ng fee ang Cashier na hindi muna na-configure at na-approve ng Admin.

### Module 2: Student Billing & Invoicing (Cashier)
- **Process (Dynamic Fee Appending)**: 
  - Sa halip na gumawa ng madaming magkakapatong na SOA, gumagamit tayo ng **Single Consolidated SOA** architecture.
  - Kapag gagawa ng bill, nag-che-check ang system kung may existing SOA ang bata para sa specific Academic Year at Semester.
  - Kung **WALA**, gagawa ng bagong SOA.
  - Kung **MERON**, i-a-append (idadagdag) ng system ang mga *bagong fees* sa existing SOA na iyon.
- **Integration**: Mase-save ito sa `payment_db.billing` at `billing_items`. Dito nanggagaling ang balance na nakikita ng estudyante sa portal nila. Kasama sa records kung sino ang nag-add (Cashier ID), kailan, at ano ang context (e.g., *Enrollment*, *Adjustment*).

### Module 3: Account Balance & Ledger (Student Portal)
- **Process**: Dito nakikita ng bata yung running SOA niya per semester. Makikita rin yung breakdown kung ano nang porsyento ang *Paid*, *Partial*, o *Unpaid*.
- **Integration**: Kung nag-append ng bagong fee ang Cashier (tulad ng *Penalty* o *Event Fee*), agad itong lalabas dito kasama yung contextual badge (e.g., `[Adjustment]`) para hindi malito ang bata.

### Module 4: Online Payment via PayMongo (Student Portal)
- **Process (The E-Wallet Flow)**:
  1. Pinipili ng bata ang channel (GCash, PayMaya) sa `online-payment.php`.
  2. Gagawa ang `PayMongoService.php` ng secure Checkout Session at ipapasa ang bata sa PayMongo portal.
  3. Babalik ang bata sa SMS2 system kapag success, at ipapakita ang initial processing summary.
- **Integration (The Webhook Brain)**: 
  - Hindi natin iniaasa ang pag-save sa pag-redirect ng bata. Si PayMongo server ay mag-si-send ng background `POST` request sa ating `api/paymongo/webhook.php`.
  - Hahanapin ng webhook ang bata, at tatawagin niya ang `PaymentAllocationService.php`.

### Module 5: Payment Allocation Engine (The Core Brain)
- **Process**: Ito ang utak ng buong ledger. Pag may pumasok na payment, i-di-distribute niya yung halaga mula sa pinaka-mataas na priority na fee (tulad ng Tuition) pababa hanggang sa maubos yung pera.
- **Integration**: *Shared Logic* ito. Ibig sabihin, ang logic na ginagamit sa Online Payment ay KAPAREHO LANG ng logic na ginagamit kapag nagbayad in-cash sa Cashier. Perfect ledger consistency.

### Module 6: Cashier Payment Collection (Walk-ins & Manual Deposit)
- **Process**: Dito rina-ruta ang mga physical payments o mga nagbayad via Bank Transfer/Deposit Slip na in-upload sa portal.
- **Integration (Google Vision AI)**: Kapag nag-upload ng deposit slip ang bata, babasahin ito ng `GoogleOcrService.php` (AI) at kukunin yung **Amount**, **Reference Number**, at **Date**. Pagtingin ng Cashier, naka-fill-out na ito at i-ve-verify na lang bago i-process via Allocation Engine.

---

## 📊 3. Reporting, Analytics, and Auditing

Lahat ng transactions (Online at Manual) ay umaakyat sa real-time reporting system:

### A. Cashier's Operational Reports
- **Ledger & History**: Real-time checking ng payment allocations per student. Makikita paano hinati-hati ang bayad nila.
- **End-of-day Analytics**: Para sa Cashier, nakikita niya ang collections niya per channel at shifts.

### B. Finance Admin's Executive Dashboard
- **System-Wide Auditing (Transaction History)**: Makikita nila ang bawat movement ng pera. May `View Details` per transaction na nagsisilbing digital audit trail.
- **Collection & Analytics Board**:
  - **Financial Health**: Total Receivables (Expected) vs Total Collections (Actual).
  - **Channel Effectiveness**: Comparison kung mas madaming gumagamit ng PayMongo or Walk-in.
  - **Gateway Health**: Naka-display kung may pending/failed checkout sessions para agad ma-troubleshoot kung nagdo-down ang payment networks.
