# Standard bank status & presentation rules (AUTHORITATIVE)

**Audience:** developers, reviewers, manual QA.  
**Scope:** OpenCart 4 UniCredit module (`mt_uni_credit`) business contract for bank status and leasing presentation.  
**Priority:** These rules override older documentation that contradicts them.  
**Implementation note:** This document defines the **business contract**. It does not assert that every runtime path already complies — mismatches are candidates for upcoming manual verification / remediation, not silent documentation “improvements.”

Cross-platform strings for the four initial standard bank statuses are **identical** across OC4, Woo, PS8, PS9, Control Panel, and peer shop modules. Do not rename them.

Related phase docs: `docs/ARCHITECTURE.md`, `docs/CONTRACTS.md`, `docs/PHASE11A.md`, `docs/PHASE11B.md`, `docs/RECOVERY.md`, `docs/INBOUND-API.md`, `docs/SECURITY-OPERATIONS.md`.

---

## 1. Two classes of status / state

### 1.1 Standard bank status

Customer- and business-facing **bank status** — the value shown wherever a UI field is labelled as bank status (admin, customer where enabled, CP, standard emails, etc.).

Until a later SmartUCF status is received, there are **exactly four** initial standard bank statuses:

| Label (AUTHORITATIVE string)          | Typical internal `status_id` |
| ------------------------------------- | ---------------------------- |
| `Неуспешно изпратен Банка - КП`       | `bank_send_failed_cp`        |
| `Неуспешно изпратен Банка - SmartUCF` | `bank_send_failed_smartucf`  |
| `Изпратен Банка - Процес 1`           | `bank_sent_process1`         |
| `Изпратен Банка - Процес 2`           | `bank_sent_process2`         |

**Not allowed as a public bank status:**

```text
Неуспешно изпратен Банка
```

(generic / legacy). Do not use it for customer/business-facing bank status.

### 1.2 Internal / service lifecycle state

Internal states used for attempt progression, transport, retry, recovery, sync, and diagnostics. Examples (illustrative, not an exhaustive enum):

```text
pending, created, submitting, retryable, timeout, outcome_unknown,
definitive_failed, sent_unknown, sync pending, sync failed,
transport failure, cp_failed_retryable, cp_outcome_unknown,
smartucf_state / process2_state values, internal progression / recovery states
```

These are **service / lifecycle states**, not standard bank statuses.

They must **not** be shown as bank status to:

- the customer;
- standard OpenCart admin order UI bank-status fields;
- standard emails;
- Control Panel order list / normal business bank-status columns;
- other normal customer/business-facing screens.

They may appear **only** on explicitly designated diagnostic surfaces (see §7).

Never conflate internal lifecycle state with standard bank status.

---

## 2. When each initial standard bank status applies

### A. `Неуспешно изпратен Банка - КП`

Use when **all** of the following hold (definitive):

- Shop order exists;
- CP order was **not** successfully created / is **not** visible in Control Panel;
- SmartUCF was **not** successfully created/sent.

This is the public bank status for definitive failure **before** successful CP create.

**Process-independent:** Process 1 and Process 2 use the **same** label for definitive CP failure.

### B. `Неуспешно изпратен Банка - SmartUCF`

Use when **all** of the following hold (definitive):

- Shop order exists;
- CP order exists and is visible in Control Panel;
- SmartUCF create/send **definitively** failed / rejected.

### C. `Изпратен Банка - Процес 1`

Use when:

- Shop order exists;
- CP order exists;
- SmartUCF create/send succeeded;
- financing process is Process 1.

### D. `Изпратен Банка - Процес 2`

Use when:

- Shop order exists;
- CP order exists;
- financing process is Process 2.

For Process 2, this standard status does **not** require proof that the order was already created or sent to SmartUCF. That is an intentional business difference from Process 1.

---

## 3. Later statuses from SmartUCF

After the initial bank status, Control Panel may request a current status from SmartUCF (manual CP action or CP periodic/daily check). SmartUCF may return a new bank status.

There is **no** guaranteed complete mapping of all possible SmartUCF values.

**AUTHORITATIVE rule:**

```text
The status is stored and displayed exactly as returned by SmartUCF.
```

- Do **not** rename it.
- Do **not** normalize it into a fixed predefined list.
- Do **not** invent a mapping.
- Same rule for OC4 and CP.

A UI field labelled as bank status may therefore show either:

- one of the four initial standard bank statuses; or
- a later **raw** SmartUCF status string.

---

## 4. Ambiguous technical outcomes

Timeout, interrupted transport, or other technical outcomes where it cannot be proven whether a remote create/send occurred may use an **internal** lifecycle state (e.g. `outcome_unknown`, `cp_outcome_unknown`).

Such an internal state:

- does **not** invent a fifth public bank status;
- does **not** automatically publish `Неуспешно изпратен Банка - КП` or `Неуспешно изпратен Банка - SmartUCF` unless the failure is later proven definitive under §2.

---

## 5. Process 1 / Process 2 failure consistency

CP create failure semantics do **not** depend on process:

```text
Process 1 + definitive CP failure  →  Неуспешно изпратен Банка - КП
Process 2 + definitive CP failure  →  Неуспешно изпратен Банка - КП
```

Generic `Неуспешно изпратен Банка` is **not** a valid public bank status.

---

## 6. Terminal order UX

If:

```text
Shop order exists
AND
a terminal public bank status exists
```

the financing flow is a **terminal business result**.

Examples of terminal public bank statuses:

```text
Неуспешно изпратен Банка - КП
Неуспешно изпратен Банка - SmartUCF
```

Customer UX must use the existing OpenCart **success / order confirmation** path when that is the OC4 contract for the entry point (Product / Cart / Checkout as documented per phase).

Terminal business failure must **not** be treated as a generic technical popup / request failure.

Customer-facing failure content on confirmation uses the canonical bank status and standard leasing presentation — **no** internal diagnostics.

---

## 7. Where standard bank status may appear

Standard bank status (or later raw SmartUCF status) may appear only on officially intended surfaces, including for OC4:

1. OpenCart admin order **list**, when the module provides a bank-status column/visualization.
2. OpenCart admin order **info**, in the dedicated UniCredit / leasing panel.
3. Customer order / confirmation UI **only** when such display is explicitly designed.
4. Success / order confirmation page when bank status is part of the agreed customer-facing content.
5. Standard order emails when bank status is included by contract.
6. Control Panel order list / order table.
7. Other explicitly agreed bank-status surfaces.

If a UI field is labelled as bank status, it must show a standard bank status or a later raw SmartUCF status — never an internal lifecycle/debug value.

---

## 8. Where diagnostic / internal information may appear

Internal lifecycle, retry, transport, correlation, and architecture details may appear **only** on designated diagnostic surfaces, for example:

- SmartUCF debug information in Control Panel;
- debug / journal export from the OC4 shop (when enabled by contract);
- specialized developer/support diagnostic panels;
- module / application logs;
- other explicitly designated service-only places.

They must not appear in normal customer or business bank-status / leasing UI.

---

## 9. Standard customer/business-facing leasing information

### 9.1 Purpose

A standardized leasing information block is used on agreed surfaces (admin UniCredit panel, Thank You / confirmation when designed, standard emails, agreed reports, etc.).

It may be adapted only in predetermined business cases (e.g. Process 2 may add second phone and EGN **only** where privacy/business rules already allow — typically admin/email audiences, never customer EGN).

Do **not** extend this block with service lifecycle/debug fields.

### 9.2 Base field set (AUTHORITATIVE structure)

Example values are illustrative; the **field set / structure** is authoritative:

```text
Статус към банката    <standard bank status or later raw SmartUCF status>
КП поръчка (ID)       <control_panel_order_id>
КП shop order_id      <shop order id>
Срок (месеци)         <months>
КОП                    <kop code>
Първоначална вноска   <first installment>
Сума на заема         <financed amount>
Месечна вноска        <monthly installment>
Обща дължима сума     <total payable>
ГЛП / ГПР             <glp> / <gpr>
```

If a field has no value at a given lifecycle moment, use the existing presentation rule for that surface (omit / empty / placeholder as already contracted) — do not invent a new presentation policy here.

Process 2 audience-sensitive appendices (EGN, phone2, customer confirmation message) remain governed by `docs/PHASE11B.md` and `docs/SECURITY-OPERATIONS.md` — they are not part of the base public bank-status semantics.

### 9.3 Forbidden in the standard leasing block

Except on designated diagnostic surfaces, the standard leasing panel must **not** show technical information such as:

```text
КП създаване
SmartUCF резултат
SmartUCF lifecycle
SmartUCF сесия
Автоматично повторно изпращане
Препоръчано действие
Последна грешка (категория)
Подсистема
Час на грешката
Корелация
CP synchronization state
lifecycle state
retry state
HTTP/transport classification
timeout/network details
internal error class
internal CP/SmartUCF stage
```

or any content that exposes internal architecture, retry/recovery, transport implementation, or integration business/technical model beyond the agreed customer/business fields.

---

## 10. Privacy / business model rule

```text
Customer-facing and normal business-facing UI must contain only information
needed for the order, financing, and the agreed bank status.
```

Do not expose Shop → CP → SmartUCF internals, retry/recovery, timeout/outcome-unknown machinery, internal state machines, correlation/error classification, or transport architecture on those surfaces.

---

## 11. Standard email rule

When a Shop order exists and the flow ends with a terminal public bank status, standard order emails must use the **same canonical bank status** (e.g. `Неуспешно изпратен Банка - КП` or `Неуспешно изпратен Банка - SmartUCF`).

Do not invent separate technical email statuses. Internal lifecycle/debug content must not appear in standard emails.

---

## 12. OpenCart-specific cart / session / order recovery

OpenCart recreateCart / session restoration / order recreation / stale `session.order_id` remediation are **implementation details**.

They must **not** change the business semantics of:

```text
bank status
terminal result
emails
customer-facing leasing information
CP / SmartUCF outcome
```

OC recovery mechanics must not invent a new public bank status. See also `docs/RECOVERY.md`.

---

## 13. Quick answers

| Question                                    | Answer                                                       |
| ------------------------------------------- | ------------------------------------------------------------ |
| Four initial public bank statuses?          | The four strings in §1.1                                     |
| Process 1 vs 2 success status?              | Process 1 requires successful SmartUCF; Process 2 does not   |
| Later SmartUCF statuses?                    | Store/display raw as returned                                |
| Can internal state be shown as bank status? | No                                                           |
| Definitive CP failure P1 and P2?            | Both → `Неуспешно изпратен Банка - КП`                       |
| Ambiguous transport?                        | Internal state only; no fifth public status                  |
| Terminal failure UX?                        | Success / order confirmation path with canonical bank status |
| Emails?                                     | Same canonical bank status; no internal diagnostics          |
| OC recovery vs bank status?                 | Recovery is technical; does not redefine bank status         |

---

## 14. Terminology

Use consistently:

```text
Standard bank status
Internal/service lifecycle state
Customer/business-facing leasing information
Diagnostic/debug information
Process 1
Process 2
Control Panel / CP
SmartUCF
Shop order
CP order
Terminal business result
OpenCart success / order confirmation
```
