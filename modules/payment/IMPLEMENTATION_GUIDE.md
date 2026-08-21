# 📚 SMS 2: Payment Management System - Master Implementation Guide
**Bestlink College of the Philippines (BCP)**

Eto yung official and comprehensive technical guide para sa Payment Management System natin. Ipapasok natin dito LAHAT ng processes, module-to-module integration, security architecture, at kung paano tumatakbo yung system natin from end-to-end.

---

## 🏛️ 1. System Architecture & Security Structure

Yung pinaka-foundation ng payment system natin ay nakasandal sa isang **Strict Role-Based Access Control (RBAC)** matrix. Ibig sabihin, hindi pwedeng mag-overlap yung trabaho ng isa't isa, at totally secured ang operations.

### A. The RBAC Matrix (Sino ang may access saan?)
- **Super Admin**: Strictly for User Management and System Security lang. **TINANGGAL** natin entirely yung access ng Super Admin sa Payment module. Bawal silang makialam sa pera at financial data.
- **Finance Admin (`finance` role)**: Siya yung boss ng Payment system. 
  - May hawak ng **ADMIN PORTAL** (`Fee Setup`, `Online Payment Configuration`).
  - May hawak ng **ADMIN REPORTING** (`Transaction History`, `Collection & Analytics`).
  - **WALA** siyang access sa Cashier portal para walang conflict of interest (separation of duties).
- **Cashier (`cashier` role)**:
  - Sila lang ang pwedeng gumamit ng **CASHIER PORTAL** (`Billing`, `Payment Collection`, `Payment History & Ledger`, `Analytics`).
  - Sila ang nagve-verify ng walk-in payments at nag-a-allocate ng bayad.
- **Student (`student` role)**:
  - May access sa Student Portal (`Account Balance`, `Online Payment`, `Payment Concerns`).

### B. Security Architecture
1. **Module & Page Security**: Gumawa tayo ng `requirePaymentPermission('permission_key')` sa bawat page para kahit i-type nila yung URL, maba-block sila kung wala silang right access (403 Forbidden).
2. **Environment Isolation**: Yung PayMongo integration natin ay may Live/Test toggle sa database (`payment_gateway_settings`). Yung mga webhooks at API calls natin ay nag-che-check muna kung anong mode tayo para hindi mag-mix yung fake payments sa live production ledger.
3. **Database Security**: Hindi tayo basta-basta sumisingit sa main system (`sms2_db`). Ginagamit natin ang sarili nating schema (`payment_db`) for configurations (like fees and gateway settings), tapos naka-join lang tayo pabalik sa `sms2_db` for auth/student data.

---

## 🔄 2. Module-to-Module Integration

Ganito nag-uusap ang mga modules natin sa isa't-isa:

### 1. Admin to Cashier Integration (Fee Setup)
- Gumagawa ang **Finance Admin** ng mga fees sa `Fee Setup & Configuration`. Isesave ito sa `payment_db.fees` (halimbawa: Tuition, Miscellaneous, ID Fee).
- Pagbukas ng **Cashier** sa `Student Billing & Invoicing`, i-fe-fetch niya dynamically yung mga *Active* fees na ginawa ng Admin para i-charge sa estudyante. Wala silang pwedeng singilin na hindi na-configure ng Admin.

### 2. Cashier to Student Portal Integration (Billing & SOA)
- Pagkatapos i-save ng **Cashier** ang billing, ma-se-save ito sa `payment_db.billing` at `billing_items`.
- Pag-login ng **Estudyante** sa `Account Balance` module, mag-ku-query ang system sa `billing_items` para ipakita nang real-time yung mga utang nila. Dito rin sila pwedeng mag-generate ng **Statement of Account (SOA)** na pwede nilang i-print bilang invoice.

### 3. Student Portal to Online Payment Integration (PayMongo Checkout)
- Sa loob ng Student Portal, kapag kinlik ng estudyante ang "Pay Online", papasok ang integration natin with PayMongo.
- Kukunin ng system yung total na babayaran, idadagdag yung `Processing Fee` (base sa Pass-on/Absorb policy na sinet ng Finance Admin), at ipapasa kay PayMongo via Server-to-Server API.

---

## 💸 3. The Complete Payment Processes

### Process 1: Online Payment Transaction (Ang PayMongo Flow)
1. **Checkout Initiation**: Pagkapili ng estudyante ng channel (GCash, Maya, Card) sa `online-payment.php`, gagawa ang `PayMongoService.php` ng secure Checkout Session.
2. **Redirection**: Mapupunta yung estudyante sa secure page ni PayMongo para magbayad.
3. **Payment Success UI**: Pagkatapos magbayad, babalik ang estudyante sa `payment-success.php?reference=PM-XXXX`. 
   - *Security Check*: I-ve-verify muna ng system kung yung `reference` ay pagmamay-ari talaga ng naka-login na estudyante. Kung oo, ipapakita yung breakdown (`Amount Applied`, `Processing Fee`, `Checkout Total`, `New Balance`).

### Process 2: The Webhook & Auto-Allocation Engine (The Core Brain)
Hindi natin ina-asa sa Success URL ang pag-update ng database kasi pwede itong i-close ng estudyante. Dito papasok yung **Webhook**.

1. **Webhook Listener**: Makaka-receive ang `api/paymongo/webhook.php` ng silent payload galing sa server ng PayMongo na nagsasabing "Bayad na si Reference PM-XXXX".
2. **Security Guardrails**:
   - I-che-check ng script yung HMAC signature gamit yung `webhook_secret` natin para maiwasan ang fake requests (Hackers).
   - I-che-check ang `Idempotency`. Kung na-process na yung payment na to before, i-i-ignore niya na.
3. **The Auto-Allocation Logic**: Tatawagin ng webhook ang `PaymentAllocationService.php`.
   - Hahanapin ng system ang lahat ng unpaid fees ng estudyante.
   - I-di-distribute niya yung ibinayad ng estudyante sa mga fees mula sa pinakaluma/highest-priority pababa hanggang maubos yung pera.
   - Kung sumobra ang bayad, i-sa-save ito ng system as `Overpayment` / Wallet balance ng bata.

### Process 3: Walk-in Payments & Google Cloud OCR
Para naman sa mga traditional na nagbabayad sa bangko (BDO, BPI):

1. **Upload via Student Portal**: I-a-upload ng bata yung picture ng deposit slip nila sa `Payment Concern Portal`.
2. **Google Vision AI (OCR)**: 
   - I-po-process ng `GoogleOcrService.php` yung image. 
   - Babasahin ng Google yung text sa deposit slip para kusang i-extract at i-auto-fill ang **Reference Number**, **Date**, at **Amount**.
3. **Cashier Validation**:
   - Pagbukas ng Cashier sa `Payment Collection Portal`, makikita nila yung in-upload ng bata at yung AI-extracted data.
   - I-ve-verify na lang ng Cashier kung tama, tapos pipindutin ang "Auto Allocate".
4. **Shared Ledger Logic**: Babagsak ulit ito sa iisang `PaymentAllocationService.php`! Ibig sabihin, online man o walk-in, iisa lang ang utak na nag-cocompute at nagba-balanse ng utang ng estudyante. Perfect consistency!

---

## 📊 4. Reporting, Analytics, and Auditing

Lahat ng nangyari sa itaas ay mag-re-reflect nang real-time sa dalawang levels ng reporting natin:

### Cashier's Level (Operational Reporting)
- **Payment History & Ledger**: Makikita ng cashier ang mga individual transactions (Cash at Online) kasama yung resibo kung paano na-distribute yung bayad.
- **Cashier Analytics**: Makikita ng cashier yung breakdown per channel at yung daily collections niya para sa end-of-day balancing.

### Finance Admin Level (System-Wide Auditing)
- **Transaction History**: Makikita ng Admin ang system-wide transactions. Merong "View Details" button na nagpapakita ng mga technical IDs (`checkout_session_id`, `category_id`) para sa madaling pag-trace kung may discrepancy.
- **Collection & Analytics**: Ito yung Real-time Dashboard na nagpapakita ng:
  - **Financial Health**: Total Receivables (Mga utang ng bata) vs Net Collections.
  - **Online vs Walk-in**: Ratio kung gaano ka-effective ang PayMongo natin vs Traditional paying.
  - **Gateway Health**: Live Server Monitoring. Pinapakita kung naka 'Live' mode tayo, at kung may ilan ang mga nakasabit na 'Pending' at 'Failed/Rejected' na online transactions para ma-troubleshoot agad kung down ang GCash o Maya.
