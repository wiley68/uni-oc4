# Phase 11B — Process 2 lifecycle

Selector (frozen):

```text
uni_proces === 1  →  Process 2  (ShopConfigurationFlags::isSecondaryProcess)
```

## Flow

```text
local OpenCart order
→ CP POST /orders (omit status → CP default cp_sent)
→ validate EGN + phone2 (server)
→ encrypt sensitive payload on financing_attempt
→ ProcessTwoLifecycleCoordinator
→ local + CP bank_sent_process2 (shop order_id = local OC order id)
→ leasing mail (admin may include EGN; customer never)
→ customer continuation (checkout/success) — no SmartUCF
```

## Fields

| Field                      | Process 1                    | Process 2                                                    |
| -------------------------- | ---------------------------- | ------------------------------------------------------------ |
| EGN                        | not rendered / not validated | required — 10 digits, first 8 = valid YYYYMMDD (no checksum) |
| phone2                     | not rendered                 | required — charset `[-0-9+() ]` + ≥1 digit                   |
| Product/Cart primary phone | required                     | required (unchanged)                                         |
| Checkout primary phone     | optional                     | optional (unchanged; phone2 is separate)                     |

EGN/phone2 are validated **before** the attempt transitions to `VALIDATING`, so a field error leaves the attempt in `issued` and the same token remains retryable.

## Privacy

- EGN/phone2 stored only in `process2_sensitive_enc` (AES-GCM `enc:v1:`), not in CP payload
- Retention redaction: 180 days (`ProcessTwoLifecycleRepository::redactExpiredSensitiveBatch`)
- Never log EGN/phone2
- Customer email: confirmation message only — **no EGN**
- Admin/`uni_email` mail: EGN + phone2 allowed

## States

```text
process2_state: not_started → process2_preparing → process2_prepared | process2_failed
```

Replay of `process2_prepared` reconciles bank status and does not duplicate mail when `process2_mail_sent=1`.

## Presentation parity (finalization)

Shared `FinancingPresentationSnapshot` + `FinancingLeasingPresenter` (frozen amounts from attempt `leasing_presentation_json`, live bank status overlay):

| Surface                            | Audience          | EGN / phone2       |
| ---------------------------------- | ----------------- | ------------------ |
| Thank You                          | customer          | never              |
| Native OC order email / alert      | customer          | never              |
| Process 2 additional customer mail | customer          | never              |
| Process 2 additional admin mail    | admin_email       | EGN + phone2 (PS9) |
| Admin Order detail                 | admin_panel       | EGN + phone2 (PS9) |
| Admin Orders list                  | status label only | —                  |

Field order: Статус към банката → КП поръчка (ID) → КП shop order_id → Срок → КОП → amounts → ГЛП/ГПР → (audience-sensitive) → Съобщение (customer Process 2).

### Runtime placement (remediation)

| Surface            | Mechanism                                                                                                                            |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| Thank You          | `catalog/view/common/success/before` → append leasing HTML to `text_message` (inside `#content`, after header)                       |
| Admin Order detail | `admin/view/sale/order_info/after` → insert after `#content` `.page-header` (not admin navbar `.container-fluid`)                    |
| Native emails      | `catalog/view/mail/order_add\|order_alert/after` append; snapshot persisted **before** `addHistory` in `OrderMaterializationService` |

Empty durable bank status does **not** invent `bank_sent_process*` labels (native mail fires at interim status).

## Hard guards

- Zero `sucfOnlineSessionStart` calls under Process 2
- Never write `bank_sent_process1` for Process 2 success
- Do not use `bank_send_failed_smartucf` for Process 2 validation/mail failures
- Version remains `2.0.2`
