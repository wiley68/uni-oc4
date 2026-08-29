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

## Status visibility (Phase 10A)

Product/Cart apply `addHistory(awaitingFinancingStatusId)` after attach.  
Resolver: dedicated module setting, else `payment_mt_uni_credit_order_status_id`.
