# MIS-5C Receipt OCR Processing — Source Implementation Report

Date: 2026-10-05

1. **Phase completed:** MIS-5C1 through MIS-5C8 source implementation is complete. Controlled Google and Hostforge production validation are pending.
2. **Files modified:** `.gitignore`, `modules/payment/.env.example`, Accounting OCR API/view/page, `PaymentConcernService.php`, `OcrUsageGuardService.php`, `GoogleVisionOcrService.php`, and the Student concern portal.
3. **Files created:** `PrivateReceiptStorageService.php`, `OcrTextProviderInterface.php`, `ReceiptParserService.php`, `ReceiptEvidenceService.php`, `ReceiptOcrProcessor.php`, `OcrStructuredAuditService.php`, and `tests/payment-mis5c-receipt-ocr-regression.php`.
4. **Database changes:** No additional MIS-5C schema or data changes. Existing approved MIS-5B tables and legacy `ocr_results` are reused.
5. **Upload security:** New uploads accept actual JPEG, PNG, and WEBP images only; enforce 5 MB; use Fileinfo, image structure/decode checks, and random 192-bit filenames; PDF is rejected.
6. **Private storage:** New receipt paths use opaque `private-receipt:` references and require an absolute `PAYMENT_PRIVATE_RECEIPT_ROOT` outside `ROOT_PATH`. Historical public receipts remain read-only compatible.
7. **Idempotency:** The existing durable server-derived key uses operation, concern, receipt identity, SHA-256, provider, feature, and feature version. Replays do not create another Google call.
8. **Quota:** The Asia/Manila monthly guard remains authoritative, defaults to 900, reserves atomically, accounts at provider boundary, and releases pre-call failures.
9. **Cache:** Cache lookup uses SHA-256 plus provider, feature, and feature version. Cache hits consume zero units and create immutable attempts with source provenance.
10. **Google Vision:** The new processor wires only `DOCUMENT_TEXT_DETECTION` through `GoogleVisionOcrService`. Provider readiness is checked before reservation.
11. **Parser:** A separate provider-neutral `ReceiptParserService` extracts amount, reference, date, time, and supported channel indicators from normalized text.
12. **Parser version:** `receipt_parser_v1` is stored with attempts and in compatibility evidence.
13. **Duplicate detection:** Exact-image and normalized-reference duplicate candidates are flagged. Amount/date context similarity is weak evidence only.
14. **Discrepancies:** Indicators include `EXACT_IMAGE_DUPLICATE`, `REFERENCE_DUPLICATE`, `AMOUNT_MISMATCH`, `TRANSACTION_DATE_MISMATCH`, missing-field indicators, low quality, unknown format, and manual review required.
15. **AUB decoupling:** The OCR endpoint, concern review page, and financial approval path no longer require AUB reconciliation. Historical AUB modules remain intact.
16. **Accounting:** The review page displays the receipt and OCR evidence, allows corrected verified values, requires explicit human confirmation, and preserves Verify/Hold/Reject decisions.
17. **Status separation:** OCR updates only OCR state/evidence. Accounting actions alone update financial verification state.
18. **Audit:** Receipt upload is audited. OCR completion uses the Payment structured audit/outbox with safe metadata and shared OCR request correlation; MIS-5B usage and attempt tables retain provider/cache/quota history.
19. **Retry:** No automatic retry. Failed OCR exposes an explicit retry action; retries create immutable attempts and remain protected from duplicate retry calls.
20. **Security regression:** 40 MIS-5C checks passed; PHP lint and `git diff --check` passed. No live provider was used.
21. **RBAC regression:** 122 canonical Payment RBAC checks and 40 personnel security checks passed.
22. **PayMongo regression:** 24 MIS-5A hardening checks passed.
23. **Real Google request:** None.
24. **OCR units:** Zero units consumed by this implementation/validation.
25. **Google readiness:** Source and mocked-provider fixture are ready. Production is not ready for a controlled call until the manual gates below pass.
26. **Hostforge blockers:** The real absolute private receipt directory is unknown; ADC placement/rotation, billing confirmation, authoritative DB environment correction, deployment, and production smoke tests remain pending.
27. **Remaining risks:** Hostforge currently needs credential rotation and verification that the app uses authoritative Payment DB port 32565. Production filesystem permissions and Google billing must be confirmed.
28. **AUB:** Deferred. No new AUB integration was started and historical AUB code was not removed globally.
29. **Database dump:** `modules/payment/database/payment_db-05.sql` remains ignored and untracked; it was not modified or deleted. `.gitignore` now narrowly covers `/modules/payment/database/payment_db-*.sql`.
30. **Next phase:** Complete manual Hostforge private storage and secure ADC installation, rotate exposed/legacy credentials, correct the production Payment DB target, deploy in a controlled window, run non-billable smoke checks, then stop at `READY FOR CONTROLLED GOOGLE OCR TEST` for explicit approval of one billable request.

## MANUAL — JAY MUST DO

Create or identify one absolute directory on Hostforge that is outside the deployed application and public web root. It must be writable by the PHP application user and unreadable through direct HTTP requests. Recommended permissions are directory `0750` and files `0640` where the hosting platform supports them.

Set this application environment variable to the confirmed real path:

```text
PAYMENT_PRIVATE_RECEIPT_ROOT=<absolute Hostforge path outside the application/web root>
```

Do not use the example placeholder from `.env.example` as the production value. Do not deploy until Hostforge confirms the real private path.

Before the first Google call, also confirm Google Cloud billing is enabled, install the rotated service-account JSON outside the web root, set `GOOGLE_APPLICATION_CREDENTIALS` to its absolute path, revoke the legacy exposed keys, and verify the application points to the authoritative Payment DB endpoint ending in `32565`.

## Controlled test expectation after all manual gates pass

- Fixture: one synthetic, non-sensitive JPG/PNG/WEBP receipt under 5 MB with a unique reference and amount.
- Unit impact: exactly one reserved and consumed Google unit on a confirmed cache miss; repeated identical requests use zero additional units.
- Records: one usage-ledger record, one immutable scan attempt, one versioned cache record, and one legacy `ocr_results` compatibility projection.
- Audit: one structured outbox/core audit event with request correlation and safe metadata; no image, credential, token, or private path.
- Failure: receipt remains viewable to authorized Accounting users, financial status remains Pending/On Hold, safe failure category is recorded, and no automatic approval or rejection occurs.
