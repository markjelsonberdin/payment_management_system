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

### B. Payment Security Architecture
1. **Module & Page Security**: Ginagamit natin ang `requirePaymentPermission('permission_key')` function. Kapag walang access ang role sa specific feature, automatic block (403 Forbidden).
2. **Environment Isolation (PayMongo)**: Mayroon tayong Live/Test toggle settings na naka-save sa database. Ang backend services dynamically switch keys depending on this admin configuration.
3. **Webhook HMAC Signature Verification**: Ang webhook script natin ay hindi pwedeng i-trigger ng basta-basta. Bago ito mag-process, dine-decode ng `PayMongoWebhookSecurityService` ang HTTP header signature para i-verify mathematically kung legit na galing PayMongo ang request bago i-allocate ang pera.
4. **Database Idempotency**: Bawat online payment intent at checkout session ay nakatali sa isang unique database id at unique reference number. Pag nakatanggap ng duplicate `payment.paid` event, ini-ignore ito ng system para maiwasan ang double-allocation (Idempotent Design).
5. **Database Isolation**: Naka-separate ang `payment_db` from `sms2_db`. Naka-join na lang for references.

---

## 🔄 2. Complete Module Processes & Integrations

Ganito nag-uusap at nagco-connect ang bawat modules natin:

### Module 1: Fee Setup & Configuration (Admin)
- **Process**: Dito gumagawa ang Finance Admin ng master list of fees (Tuition, Misc, Adjustments).
- **Integration**: Ang mga *Active* fees dito ang SIYANG GINAGAMIT ng Cashier sa Student Billing. Bawal maningil ng fee ang Cashier na hindi muna na-configure at na-approve ng Admin.

### Module 2: Student Billing & Context-Aware Allocation (Cashier & System)
- **Process (Dynamic Fee Appending & Targeted Allocation)**: 
  - Sa halip na gumawa ng madaming magkakapatong na SOA, gumagamit tayo ng **Single Consolidated SOA** architecture per academic term.
  - Kapag nagbayad ang estudyante online or walk-in, ang payment logic ay nakabase sa **Context-Aware Allocation** (na pumalit sa dating Partial Payment Rule).
  - Kung `allocation_context = ENROLLMENT_PRIORITY`, ang bayad ay pumasok sa priority waterfall queue (Tuition muna, sunod ang Misc, etc.).
  - Kung `allocation_context = SPECIFIC_ITEM`, ang system ay magla-lock-on sa isang specific fee (e.g. Graduation Fee or ID replacement) para doon eksakto maibawas ang bayad.

### Module 3: Account Balance & Ledger (Student Portal)
- **Process**: Dito nakikita ng bata yung running SOA niya per semester. Pinapakita kung anong porsyento ang *Paid*, *Partial*, o *Unpaid*.
- **Integration**: Dito nagti-trigger ang API call sa `available-channels.php`. Kung enabled ng Admin ang QR Ph o GCash, agad itong magpapakita sa UI ng bata.

### Module 4: Online Payment via PayMongo (Student Portal)
Hinati natin sa dalawang distinct API pipelines ang processing base sa nature ng channel:

- **Flow A (The E-Wallet / Card Checkout Flow)**:
  1. Pinipili ng bata ang channel (GCash, PayMaya, Card).
  2. Gagawa ang `create-checkout.php` ng secure Checkout Session via PayMongo API. Naka-bind ito sa `checkout_session_id`.
  3. Ire-redirect ang bata sa secure payment page ng PayMongo.
  
- **Flow B (The Dynamic QR Ph Intents Flow)**:
  1. Pinipili ng bata ang **QR Ph**.
  2. Imbis na redirect, tatawagin ng system ang `create-qr-payment.php`. Ito ay gagamit ng **Payment Intents API**.
  3. Gagawa ng server-side intent, i-a-attach sa isang QR Payment Method, at ibabato ang Base64 Image pabalik sa frontend.
  4. Magdi-display ang QR Ph code sa screen ng estudyante (no redirects needed). May JS Polling engine na 4-second intervals para abangan ang status ng transaction in real-time.

- **Integration (The Webhook Brain)**: 
  - Hindi natin iniaasa ang pag-save sa UI redirect. Si PayMongo server ay mag-si-send ng background POST request sa ating `api/paymongo/webhook.php`.
  - Dedesisyunan ng webhook kung ito ay `checkout_session.payment.paid` (Flow A) o `payment.paid` (Flow B), i-ve-verify ang security signature, babasahin ang intent ID, at tsaka tatawagin ang `PaymentAllocationService.php`.
  - May hawak ding resumption logic ang system. Kung na-expired ang QR Ph intent (`qrph.expired`), pwede i-resume ng bata, at ang backend (`resume-payment.php`) ay re-re-generate ng panibagong QR payment method na naka-kabit pa rin sa lumang DB record (clean financial logs).

### Module 5: Payment Allocation Engine (The Core Brain)
- **Process**: Ito ang utak ng buong ledger. Pag may pumasok na verified payment mula sa Webhook or Cashier, i-di-distribute niya yung halaga base sa `allocation_context`.
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
