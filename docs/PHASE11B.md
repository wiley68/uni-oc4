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

## Hard guards

- Zero `sucfOnlineSessionStart` calls under Process 2
- Never write `bank_sent_process1` for Process 2 success
- Do not use `bank_send_failed_smartucf` for Process 2 validation/mail failures
- Version remains `2.0.2`
