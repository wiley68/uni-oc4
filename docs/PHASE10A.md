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
→ local_order_prepared
```

Checkout still **reuses** `session.order_id` via `CheckoutExistingOrderGateway` (never `addOrder()`).  
Product/Cart **create** via `OpenCartOrderMaterializer::materializeNew` → `model_checkout_order->addOrder()`.

## Root cause (why Admin looked empty)

Phase 6/7/8 already wired Product/Cart submit → `OrderMaterializationService::materializeAndBind` → `addOrder()`.

Live evidence (status 0 rows with payment `mt_uni_credit.mt_uni_credit` and `order_created` attempts) proved orders were created.

They stayed at **`order_status_id = 0`** because:

```text
module_mt_uni_credit_awaiting_financing_order_status_id
```

was unset, so `addHistory(awaiting)` was skipped. Admin Sales → Orders hides status 0.

Checkout looked “working” because `payment/mt_uni_credit.confirm` calls `addHistory(payment_mt_uni_credit_order_status_id)` after success.

## Phase 10A fix

1. `FinancingOrderStatusPolicy::resolveConfiguredAwaitingStatusId(module, payment)` — fallback to payment order status when module awaiting is 0.
2. Product / Cart / Checkout models wire that resolver.
3. Admin module setting + UI for awaiting financing status (0 = use Payment status).

After submit, Product/Cart orders leave status 0 and become Admin-visible. No CP/SmartUCF.

## Payment identity

```text
mt_uni_credit.mt_uni_credit
```

## Guest

`customer_id = 0` is first-class for Product/Cart.

## Crash / duplicate

Unchanged Phase 6 contracts: operation lock + correlation + attach-once. Replay from ISSUED/VALIDATING/ORDER_CREATING returns the same order.

## Next

Only after Phase 10A PASS → Phase 10B CP lifecycle.
