# Phase 5B Catalog Service Contract

## Sakop at hangganan

Ito ang contract para sa susunod na PHP/API implementation. Hindi pa nito binabago ang application. Ang Payment DB (`hf_db_bfim0j6t`) ang authoritative catalog store; ang Core DB (`hf_db_w3krndst`) ay audit destination lamang. Cashier selling, billing, fees, AR, assessments, inventory, receipts, at payment allocation ay out of scope.

## Mutation request envelope

Lahat ng catalog mutation ay dapat may:

- caller-generated UUID `correlation_id` na globally unique per logical mutation;
- canonical normalized request representation;
- lowercase SHA-256 `request_fingerprint` ng canonical representation;
- authenticated actor snapshot at target entity/version data.

Ang canonical representation ay stable JSON na may sorted object keys, explicit nulls, normalized strings/enums, fixed decimal representation, at walang volatile transport fields. Required ang fingerprint pero hindi ito globally unique.

## Idempotency decisions

Sa simula ng mutation, basahin at i-lock ang `payment_audit_outbox` row by `correlation_id`.

- Walang row: maaaring ipagpatuloy ang mutation.
- Same correlation ID + same fingerprint: huwag ulitin ang mutation; ibalik ang stored `committed_result`.
- Same correlation ID + different fingerprint: reject as `409 CORRELATION_ID_CONFLICT`; walang mutation.
- Same fingerprint + different correlation IDs: legal at hindi conflict.

## Transaction boundary

Isang Payment DB transaction ang dapat mag-lock ng affected catalog aggregate, mag-validate, mag-mutate, at mag-insert ng exactly one Pending outbox row. Commit lang kapag parehong successful ang catalog mutation at outbox insert. Ang unique correlation key ang final race protection.

Kapag nag-fail ang Core audit write pagkatapos ng Payment commit, hindi iro-roll back ang committed Payment mutation. Ibalik ang committed business result kasama ang correlation ID at audit delivery state na `Pending`/`Failed`; ang retry worker ang magre-reconcile.

## Activation guard

Bago gawing `Active` ang item, service must lock the item, its variants, current/future Active price rows relevant to the decision, at Active applicability rows. Lahat ng ito ay kailangang pumasa:

1. May at least one `Active` variant.
2. Bawat `Active` variant ay may exactly one `Active` price na current: `effective_from <= UTC_TIMESTAMP()` at `effective_to IS NULL OR effective_to > UTC_TIMESTAMP()`.
3. Walang dalawang `Active` price periods na nag-o-overlap para sa parehong variant.
4. `ALL` means zero Active applicability rows.
5. `RESTRICTED` means at least one Active applicability row.
6. `BOOK` item type is always rejected with `BOOK_METADATA_CAPABILITY_REQUIRED` hanggang ma-implement ang later Book metadata phase.

Hindi sapat na may isang valid variant kung may ibang Active variant na walang current official price o may multiple current prices.

## Price lifecycle

Activated prices are immutable in place. Correction/change creates a new price with a new effective window; the prior price is closed (`effective_to`) and retired when appropriate. Service validation must reject overlaps under row locks. Currency is `PHP` for this phase unless a later approved contract expands it.

## Applicability lifecycle

Applicability filters catalog eligibility only. Hindi ito lumilikha ng billing, assessment, debt, AR, o payment allocation. `RESTRICTED -> ALL` must set existing Active assignments to `Inactive`, preserve them as history, populate deactivation actor/time, then update the item mode in the same transaction. Walang hard delete.

## Response requirements

Every mutation response exposes `correlation_id`, `request_fingerprint`, committed entity/result, `idempotent_replay`, and `audit_delivery_status`. Suggested transport behavior:

- `200/201`: new commit or safe replay; audit may be Delivered or Pending.
- `202`: optional when business commit succeeded but audit delivery remains pending.
- `409`: correlation reused with different fingerprint, stale version, or lifecycle conflict.
- `422`: activation/applicability/price prerequisites failed.

## Later implementation verification

SQL validation cannot prove locking, replay, conflict handling, retry delivery, concurrent activation, or BOOK enforcement. Those stay `NOT YET VERIFIED` until PHP/API code and automated integration/concurrency tests exist.
