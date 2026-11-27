# Phase 10B — Durable Control Panel Lifecycle

## Boundary

```text
durable local OpenCart order
→ durable CP submission (create or recover)
→ CP order identity persisted
→ attempt state cp_created
→ STOP (no SmartUCF / Process execution / bank redirect)
```

Module release version remains frozen at `2.0.2` (`ModuleConstants::VERSION`) for the development cycle — see `docs/RELEASE.md` / `docs/CONTRACTS.md`.

## CP contract (current Control Panel)

| Item                 | Value                                                                                    |
| -------------------- | ---------------------------------------------------------------------------------------- |
| Method               | `POST`                                                                                   |
| Route                | `/api/v1/orders` (client path `/orders`)                                                 |
| Auth                 | `Authorization: Bearer <access_token>`. The client does not replay a create after a remote 401. |
| Content-Type         | `application/json`                                                                       |
| Success              | HTTP 201 created or HTTP 200 idempotent replay; `success=true`; `data.id` = CP PK        |
| Conflict             | HTTP 409 when same `(shop_id, order_id)` exists with different semantic hash             |
| CP order id          | `data.id`                                                                                |
| Shop order reference | request `order_id` (max 13) = local OpenCart `order_id` string                           |
| Idempotency          | `(shop_id, order_id)` + semantic payload hash — **no GET lookup**                        |

Process 1 shops: omit `status` / `status_id` (CP defaults `cp_sent`).
Process 2 shops (`uni_proces === 1`): also omit `status` / `status_id` on Phase 10B create — same CP defaults.  
`bank_sent_process1` / `bank_sent_process2` / `bank_send_failed_smartucf` are written only after Phase 11 bank-side actions. CP order created ≠ sent to bank.

## Shared services

- `ControlPanelOrderPayloadBuilder` — FinancingSnapshot/submission + local order → CP body
- `ControlPanelOrderLifecycleService` — submit/recover for Product, Cart, Checkout
- `ControlPanelClient::createOrder()` — authenticated POST `/orders`

## Attempt states (Phase 10B)

```text
order_created | eligible cp_failed_retryable → confirmed dispatch claim (cp_submitting)
cp_submitting → cp_created | terminal_failed | cp_outcome_unknown
unresolved cp_submitting | cp_outcome_unknown → operator reconciliation
cp_created → local continuation
```

Only the caller that entered `cp_submitting` and confirmed the claim may
invoke the create. Subsequent invocations stop at unresolved
`cp_submitting` or `cp_outcome_unknown`.

## Persistence

`mt_uni_credit_financing_attempt`:

- `control_panel_order_id` (BIGINT) — CP `data.id`
- `cp_payload` — frozen POST body preserved through dispatch and recovery
- `last_error_class` — body fingerprint during preparation and dispatch, then the recorded error classification or NULL on success
- `state` — lifecycle
- `cp_status_sync_*` — durable outbound PATCH confirmation (separate from local bank*sent*\*)

## Crash / timeout recovery

CP has no lookup-by-shop-order GET. An eligible `cp_failed_retryable` attempt
can dispatch its frozen `cp_payload` after validation and a confirmed claim.
Same semantic hash → HTTP 200 + same `data.id`. Different hash → 409 (`cp_conflict`).

Ambiguous outcomes are recorded as `cp_outcome_unknown`. An unapplied
outcome write leaves the attempt in `cp_submitting` with its fingerprint.
Either state blocks automatic creation and `bank_send_failed_cp`. An
interruption immediately before transport can also require reconciliation.
See [Recovery](RECOVERY.md) for the disposition rules.

HTTP ≥500 without definitive machine code → `cp_outcome_unknown` (no blind re-POST).
Definitive machine codes (`invalid_payload`, `semantic_conflict`, `unsupported_status`, `shop_not_found`, `order_not_found`) → `terminal_failed` / non-retryable.

## Error taxonomy

`cp_auth_failed`, `cp_transport_failed`, `cp_timeout`, `cp_invalid_response`, `cp_rejected`, `cp_conflict`, `cp_recovery_failed`

Customer-facing copy remains generic (order exists; financing system send failed).

## Checkout empty telephone

Checkout primary telephone remains **optional** (`telephone=''` is valid).  
CP `StoreOrderRequest` accepts `phone=''` (nullable/empty string persisted as `''` on non-null column).  
OpenCart may omit telephone when `config_telephone_required` is off — financing POSTs `phone=''` without placeholders.

Product/Cart still require telephone.

SmartUCF acceptance of empty `clientPhone` remains a Phase 11 real-bank test — not validated here.

## OpenCart status

| Entry    | OC status after local materialization / Phase 10B                                    |
| -------- | ------------------------------------------------------------------------------------ |
| Product  | UniCredit payment `payment_mt_uni_credit_order_status_id` („Състояние на поръчката“) |
| Cart     | Same payment-method setting                                                          |
| Checkout | Native Checkout/payment confirm lifecycle on CP success                              |

CP success alone does not change OpenCart status. CP failure leaves Product/Cart at the configured payment status (order not deleted / not reverted to 0).

Checkout CP failure before native success: if `order_status_id <= 0`, apply `addHistory(config_order_status_id)` (typically Pending) so Admin is not Missing Orders. That status remains eligible for same-cart native order reuse. CP create eligibility follows the durable attempt state. Success still uses payment method status via native confirm.

There is **no** separate module setting `module_mt_uni_credit_awaiting_financing_order_status_id`.

## Stale checkout `session.order_id`

Invariant: `session.order_id` may be reused only when it still represents the current cart and is lifecycle-valid for confirm reuse. A Voided order for an older cart must not supply price/products/CP payload for a changed cart.

See `docs/RECOVERY.md` (Checkout / stale session.order_id). Guards: `CheckoutSessionOrderGuard`, `CheckoutOrderCartParity`, `CheckoutLiveGrandTotal` (confirm-equivalent total, not `cart->getTotal()`), events on cart add/edit/remove + confirm before.

## Out of scope

SmartUCF, Process 1/2 execution, bank redirect, `updateOrderStatus`, final customer bank redirect.
