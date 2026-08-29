# Phase 10A — Product + Cart local order materialization parity

**Boundary:** Product and Cart reach the same durable local-order state as Checkout: `local_order_prepared`.  
**Out of scope:** CP create, Process 1/2, SmartUCF, bank redirect.

## Unified local boundary

```text
Product / Cart / Checkout
→ validated financing
→ exactly one durable local OpenCart order
→ attempt bound (order_created)
→ order correlation row
→ interim visible OC status (Pending)
→ local_order_prepared
```

Checkout still **reuses** `session.order_id` via `CheckoutExistingOrderGateway` (never `addOrder()`).  
Product/Cart **create** via `OpenCartOrderMaterializer::materializeNew` → `model_checkout_order->addOrder()`.

## Admin visibility

OC 4.1 Admin `getOrders()` default filter:

```text
WHERE order_status_id > '0'
```

Status `0` is listed only under filter **Пропуснати поръчки** (`text_missing`).

## Root causes (why Product/Cart looked missing)

1. **Status 0** — `addHistory` never persisted (empty `oc_order_history`).
2. **Empty `order_total.extension`** — drafts used `extension => ''`. Native `addHistory()` loads `extension/{extension}/total/{code}` when moving into a processing-list status; empty extension breaks that path before `editOrderStatusId` / history insert.
3. Earlier fallback to `payment_mt_uni_credit_order_status_id` = Processing was wrong for Phase 10A semantics.

## Fix

1. Totals use `extension => 'opencart'` (native OC totals modules).
2. Interim status = module awaiting setting, else **`config_order_status_id` (Pending)** — never payment Processing.
3. `ensureInterimVisibleStatus()` after bind **and** on bound-order retry (idempotent if already Pending).

## Payment identity

```text
mt_uni_credit.mt_uni_credit
```

## Next

Only after Phase 10A PASS → Phase 10B CP lifecycle.
