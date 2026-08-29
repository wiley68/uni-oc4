# Phase 10B — Durable Control Panel Lifecycle

## Boundary

```text
durable local OpenCart order
→ durable CP submission (create or recover)
→ CP order identity persisted
→ attempt state cp_created
→ STOP (no SmartUCF / Process execution / bank redirect)
```

## CP contract (current Control Panel)

| Item                 | Value                                                                                    |
| -------------------- | ---------------------------------------------------------------------------------------- |
| Method               | `POST`                                                                                   |
| Route                | `/api/v1/orders` (client path `/orders`)                                                 |
| Auth                 | `Authorization: Bearer <access_token>` (Phase 4 client; one 401 → login/refresh → retry) |
| Content-Type         | `application/json`                                                                       |
| Success              | HTTP 201 created or HTTP 200 idempotent replay; `success=true`; `data.id` = CP PK        |
| Conflict             | HTTP 409 when same `(shop_id, order_id)` exists with different semantic hash             |
| CP order id          | `data.id`                                                                                |
| Shop order reference | request `order_id` (max 13) = local OpenCart `order_id` string                           |
| Idempotency          | `(shop_id, order_id)` + semantic payload hash — **no GET lookup**                        |

Process 1 shops: omit `status` / `status_id` (CP defaults `cp_sent`).
Process 2 shops (`uni_proces === 1`): include `status` / `status_id=bank_sent_process2` on create only (PS9 parity). This does **not** execute Process 2 / SmartUCF.

## Shared services

- `ControlPanelOrderPayloadBuilder` — FinancingSnapshot/submission + local order → CP body
- `ControlPanelOrderLifecycleService` — submit/recover for Product, Cart, Checkout
- `ControlPanelClient::createOrder()` — authenticated POST `/orders`

## Attempt states (Phase 10B)

```text
order_created → cp_submitting → cp_created
cp_submitting → cp_failed_retryable | cp_outcome_unknown
cp_failed_retryable → cp_submitting (retry)
cp_outcome_unknown → cp_submitting (recover via re-POST)
cp_created → (replay local only; no CP call)
```

Forbidden: `cp_created → cp_submitting` without clearing durable CP id (replay returns existing).

## Persistence

`mt_uni_credit_financing_attempt`:

- `control_panel_order_id` (BIGINT) — CP `data.id`
- `cp_payload` — frozen POST body (recovery source of truth)
- `last_error_class` — taxonomy below
- `state` — lifecycle

## Crash / timeout recovery

CP has no lookup-by-shop-order GET. Recovery = re-POST **frozen** `cp_payload`.
Same semantic hash → HTTP 200 + same `data.id`. Different hash → 409 (`cp_conflict`).

Timeout / unknown outcome → `cp_outcome_unknown` → next submit re-POSTs frozen payload (never a blind second create with a rebuilt body).

## Error taxonomy

`cp_auth_failed`, `cp_transport_failed`, `cp_timeout`, `cp_invalid_response`, `cp_rejected`, `cp_conflict`, `cp_recovery_failed`

Customer-facing copy remains generic (order exists; financing system send failed).

## Checkout empty telephone

Module still allows `telephone=''`. Payload sends `phone=''`.
**CP StoreOrderRequest currently requires non-empty phone** (422). This is a known CP-vs-shop contract tension; OC4 does not invent placeholders.

## OpenCart status (unchanged)

| Entry    | OC status after Phase 10B           |
| -------- | ----------------------------------- |
| Product  | Pending (awaiting financing status) |
| Cart     | Pending                             |
| Checkout | Processing (native)                 |

## Out of scope

SmartUCF, Process 1/2 execution, bank redirect, `updateOrderStatus`, final customer bank redirect.
