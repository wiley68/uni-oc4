# Phase 11B — Process 2 lifecycle

Selector (frozen):

```text
uni_proces === 1  →  Process 2  (ShopConfigurationFlags::isSecondaryProcess)
```

## Flow

```text
local OpenCart order
→ CP POST /orders (omit status → CP default cp_sent)
→ finalized consent evidence; process2_sensitive_enc remains SQL NULL
→ ProcessTwoLifecycleCoordinator:
   claimPreparing → durable cp_status_sync target (bank_sent_process2)
   → local bank_sent_process2 → PATCH → markPrepared → leasing mail (claim token)
→ customer continuation (checkout/success) — no SmartUCF
```

## Fields

Product/Cart collect five applicant fields: first name, surname, address, primary phone and email. Existing additional address data remains supported. Process 2 EGN and secondary phone are permanently removed from all three entry points. Explicit applicant allowlists exclude old scalar, array, nested and prefixed inputs before normalization/serialization. Process 1 request semantics are unchanged.

Primary-phone contracts remain distinct: Product frontend uses its existing charset validation; Cart frontend uses digits/+ with its existing minimum length. Both backends require a nonempty phone of at most 32 characters. Native Checkout takes verified order/session customer and address data; its telephone remains optional, with a 32-character limit when supplied. Both processes still require the existing applicable mandatory consents.

## Privacy

New attempts leave `process2_sensitive_enc` SQL NULL. No new sensitive DTO, encryption or ciphertext write occurs. Process 2 handoff and historical mail retry require no ciphertext or sensitive cipher. Historical ciphertext remains stored under the existing retention policy (180 days from `updated_at`); no migration or bulk cleanup accompanies this change.

The common presenter ignores legacy EGN/phone2 DTO values for every audience. Presentation and retry never decrypt historical ciphertext, including malformed ciphertext. Non-sensitive frozen financing values, consent hashes, process identity, recipients, mail claims and deduplication remain unchanged. Legacy outcomes without consent evidence retain their read-only restrictions.

Diagnostic write/read/export and applicable text paths redact retired aliases case-insensitively, including nested arrays/JSON: `egn`, `clientegn`, `phone2`, `unipayment_egn`, `unipayment_phone2`, `second_phone`, `secondary_phone`, `personal_id`, `phone_2`.

## States

```text
process2_state: not_started → process2_preparing → process2_prepared | process2_failed
```

Replay of `process2_prepared` reconciles bank status and does not duplicate mail when `process2_mail_sent=1`.

## Presentation parity (finalization)

**Canonical field set and bank-status rules:** `docs/BANK-STATUS-AND-PRESENTATION.md`.

Shared `FinancingPresentationSnapshot` + `FinancingLeasingPresenter` (frozen amounts from attempt `leasing_presentation_json`, live bank status overlay):

| Surface                            | Audience          | EGN / phone2       |
| ---------------------------------- | ----------------- | ------------------ |
| Thank You                          | customer          | never              |
| Native OC order email              | customer          | never              |
| Native admin alert                 | admin_email       | never  |
| Process 2 additional customer mail | customer          | never              |
| Process 2 additional admin mail    | admin_email       | never |
| Admin Order detail                 | admin_panel       | never |
| Admin Orders list                  | status label only | —                  |

Base leasing block field order (AUTHORITATIVE structure):

```text
Статус към банката → КП поръчка (ID) → КП shop order_id → Срок (месеци) → КОП
→ Първоначална вноска → Сума на заема → Месечна вноска → Обща дължима сума → ГЛП / ГПР
```

Only the existing customer confirmation message is appended for Process 2; EGN/phone2 are excluded for all audiences. Do **not** append internal lifecycle / retry / SmartUCF diagnostic rows to this block.

`process2_state` and similar values are **internal/service lifecycle states** — never shown as bank status on customer/business surfaces.

### Runtime placement (remediation)

| Surface               | Mechanism                                                                                                                            |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Thank You             | `catalog/view/common/success/before` → append leasing HTML to `text_message` (inside `#content`, after header)                       |
| Admin Order detail    | `admin/view/sale/order_info/after` → last `card mb-3` inside `#content` `.container-fluid` (after history, before `#modal-customer`) |
| Native customer email | `catalog/view/mail/order_add/after` → HTML table (`Mail::setHtml`)                                                                   |
| Native admin alert    | `catalog/view/mail/order_alert/after` → `<br/>` line HTML (`Mail::setHtml`, not `setText`)                                           |

Empty durable bank status does **not** invent `bank_sent_process*` labels (native mail fires at interim status).

**Admin alert formatting:** `order_alert.twig` is br-line markup via `setHtml`. Plain `\\n` text collapses in HTML clients — use `FinancingLeasingPresenter::renderBrHtml()`.

## Hard guards

- Zero `sucfOnlineSessionStart` calls under Process 2
- Never write `bank_sent_process1` for Process 2 success
- Do not use `bank_send_failed_smartucf` for Process 2 validation/mail failures
- Definitive CP failure (Process 1 or 2) uses public status `Неуспешно изпратен Банка - КП` — never generic `Неуспешно изпратен Банка`
- Prepared module version: `2.0.5` (RELEASE-PREP-01); no Git release or deployment
