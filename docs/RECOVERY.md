# Recovery — financing attempt / local order

## Crash window (Product / Cart)

```text
addOrder() creates order N
→ correlation insert (attempt_id, store_id, order_id)
→ process interrupted before client sees success
→ retry same attempt
→ recover N (no second addOrder)
```

## Duplicate click

Operation lock `(store_id, entry_point, operation_key_hash)` serializes materialization. Bound `order_id` short-circuits to replay `local_order_prepared`.

## Checkout

No `addOrder()` from UniCredit payment. Recovery = reuse `session.order_id` when status is 0, awaiting-financing, or OC4.1 void (`config_void_status_id`).

## Status visibility (Phase 10A / remediated)

Product/Cart apply `addHistory(payment_mt_uni_credit_order_status_id)` after attach.  
Source: UniCredit payment method „Състояние на поръчката“ only.  
Fallback: if that setting is missing/invalid (`<= 0`), no status update is applied (order may remain at 0 until configured).  
Checkout is not rewritten by this initializer.

## CP create crash window (Phase 10B)

```text
POST /orders → CP creates N
→ OC crashes before control_panel_order_id write
→ retry same attempt
→ re-POST frozen cp_payload
→ CP returns same data.id (idempotent)
→ persist control_panel_order_id + cp_created
```

Timeout / unknown: state `cp_outcome_unknown`, then same re-POST recovery. Never invent a second shop `order_id`.

CP has no GET-by-local-order lookup; recovery is create-idempotency only. See `docs/PHASE10B.md`.
